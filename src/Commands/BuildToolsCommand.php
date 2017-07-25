<?php
/**
 * Terminus Plugin that contain a collection of commands useful during
 * the build step on a [Pantheon](https://www.pantheon.io) site that uses
 * a GitHub PR workflow.
 *
 * See README.md for usage information.
 */

namespace Pantheon\TerminusBuildTools\Commands;

use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\ProcessUtils;
use Consolidation\AnnotatedCommand\AnnotationData;
use Consolidation\AnnotatedCommand\CommandData;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Semver\Comparator;

/**
 * Build Tool Commands
 */
class BuildToolsCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;

    const TRANSIENT_CI_DELETE_PATTERN = '^ci-';
    const PR_BRANCH_DELETE_PATTERN = '^pr-';
    const DEFAULT_DELETE_PATTERN = self::TRANSIENT_CI_DELETE_PATTERN;

    protected $tmpDirs = [];

    /**
     * Object constructor
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Register our shutdown function if any of our commands are executed.
     *
     * @hook init
     */
    public function initialize(InputInterface $input, AnnotationData $annotationData)
    {
        // Insure that $workdir will be deleted on exit.
        register_shutdown_function([$this, 'cleanup']);
    }

    /**
     * Terminus requires php 5.5, so we know we have at least that version
     * if we get this far.  Warn the user if they are using php 5.5, though,
     * as we recommend php 5.6 or later (e.g. for Drupal 8.3.0.)
     */
    protected function warnAboutOldPhp()
    {
        if (Comparator::lessThan(PHP_VERSION, '5.6.0')) {
            $this->log()->warning('You are using php {version}; it is strongly recommended that you use at least php 5.6. Note that older versions of php will not work with newer template projects (e.g. Drupal 8.3.0).', ['version' => PHP_VERSION]);
        }
    }

    /**
     * Recover the session's machine token.
     */
    protected function recoverSessionMachineToken()
    {
        if (!$this->session()->isActive()) {
            $this->log()->notice('No active session.');
            return;
        }

        $user_data = $this->session()->getUser()->fetch()->serialize();
        if (!array_key_exists('email', $user_data)) {
            $this->log()->notice('No email address in active session data.');
            return;
        }

        // Look up the email address of the active user (as auth:whoami does).
        $email_address = $user_data['email'];

        // Try to look up the machine token using the Terminus API.
        $tokens = $this->session()->getTokens();
        $token = $tokens->get($email_address);
        $machine_token = $token->get('token');

        // If we can't get the machine token through regular Terminus API,
        // then serialize all of the tokens and see if we can find it there.
        // This is a workaround for a Terminus bug.
        if (empty($machine_token)) {
            $raw_data = $tokens->serialize();
            if (isset($raw_data[$email_address]['token'])) {
                $machine_token = $raw_data[$email_address]['token'];
            }
        }

        return $machine_token;
    }

    /**
     * Return the list of available organizations
     */
    protected function availableOrgs()
    {
        $orgs = array_map(
            function ($org) {
                return $org->getLabel();
            },
            $this->session()->getUser()->getOrganizations()
        );
        return $orgs;
    }

    /**
     * Validate requested site name before prompting for additional information.
     *
     * @hook init build-env:create-project
     */
    public function validateSiteName(InputInterface $input, AnnotationData $annotationData)
    {
        $github_org = $input->getOption('org');
        $site_name = $input->getOption('pantheon-site');
        $source = $input->getArgument('source');
        $target = $input->getArgument('target');

        // If only one parameter was provided, then it is the TARGET
        if (empty($target)) {
            $target = $source;
            $source = 'd8';
        }

        // If the source site is a common alias, then replace it with its expanded value
        $source = $this->expandSourceAliases($source);

        // If an org was not provided for the source, then assume pantheon-systems
        if (strpos($source, '/') === FALSE) {
            $source = "pantheon-systems/$source";
        }

        // If an org was provided for the target, then extract it into
        // the `$org` variable
        if (strpos($target, '/') !== FALSE) {
            list($github_org, $target) = explode('/', $target, 2);
        }

        // If the user did not explicitly provide a Pantheon site name,
        // then use the target name for that purpose. This will probably
        // be the most common usage -- with matching GitHub / Pantheon
        // site names.
        if (empty($site_name)) {
            $site_name = $target;
        }

        // Before we begin, check to see if the requested site name is
        // available on Pantheon, and fail if it is not.
        $site_name = strtr(strtolower($site_name), '_ ', '--');
        if ($this->sites()->nameIsTaken($site_name)) {
            throw new TerminusException('The site name {site_name} is already taken on Pantheon.', compact('site_name'));
        }

        // Assign variables back to $input after filling in defaults.
        $input->setArgument('source', $source);
        $input->setArgument('target', $target);
        $input->setOption('org', $github_org);
        $input->setOption('pantheon-site', $site_name);
    }

    /**
     * Ensure that the user has provided credentials for GitHub and Circle CI,
     * and prompt for them if they have not.
     *
     * n.b. This hook is not called in --no-interaction mode.
     *
     * @hook interact build-env:create-project
     */
    public function ensureCredentials(InputInterface $input, OutputInterface $output, AnnotationData $annotationData)
    {
        // Ask for a GitHub token if one is not available.
        $github_token = getenv('GITHUB_TOKEN');
        while (empty($github_token)) {
            $github_token = $this->io()->askHidden("Please generate a GitHub personal access token by visiting the page:\n\n    https://github.com/settings/tokens\n\n For more information, see:\n\n    https://help.github.com/articles/creating-an-access-token-for-command-line-use.\n\n Give it the 'repo' (required) and 'delete-repo' (optional) scopes.\n Then, enter it here:");
            $github_token = trim($github_token);
            putenv("GITHUB_TOKEN=$github_token");

            // Validate that the GitHub token looks correct. If not, prompt again.
            if ((strlen($github_token) < 40) || preg_match('#[^0-9a-fA-F]#', $github_token)) {
                $this->log()->warning('GitHub tokens should be 40-character strings containing only the letters a-f and digits (0-9). Please enter your token again.');
                $github_token = '';
            }
        }

        // Ask for a Circle token if one is not available.
        $circle_token = getenv('CIRCLE_TOKEN');
        while (empty($circle_token)) {
            $circle_token = $this->io()->askHidden("Please generate a Circle CI personal API token by visiting the page:\n\n    https://circleci.com/account/api\n\n For more information, see:\n\n    https://circleci.com/docs/api/v1-reference/#getting-started\n\n Then, enter it here:");
            $circle_token = trim($circle_token);
            putenv("CIRCLE_TOKEN=$circle_token");

            // Validate that the CircleCI token looks correct. If not, prompt again.
            if ((strlen($circle_token) < 40) || preg_match('#[^0-9a-fA-F]#', $circle_token)) {
                $this->log()->warning('GitHub tokens should be 40-character strings containing only the letters a-f and digits (0-9). Please enter your token again.');
                $circle_token = '';
            }
        }

        // If the user did not specify an admin password, then prompt for one.
        $adminPassword = $input->getOption('admin-password');
        if (empty($adminPassword)) {
            $adminPassword = getenv('ADMIN_PASSWORD');
        }
        if (empty($adminPassword)) {
            $adminPassword = $this->io()->askHidden("Enter the password you would like to use to log in to your test site,\n or leave empty for a random password:", function ($value) { return $value; });
        }
        $input->setOption('admin-password', $adminPassword);

        // Encourage the user to select a team
        $team = $input->getOption('team');
        if (empty($team)) {
            $team = getenv('TERMINUS_TEAM');
        }
        if (empty($team)) {
            $orgs = array_values($this->availableOrgs());
            if (!empty($orgs)) {
                array_unshift($orgs, '-');
                $team = $this->io()->choice('Select a team for this site', $orgs);
            }
        }
        if ($team != '-') {
            $input->setOption('team', $team);
        }
    }

    /**
     * Ensure that the user has not supplied any parameters with invalid values.
     *
     * @hook validate build-env:create-project
     */
    public function validateCreateProject(CommandData $commandData)
    {
        $input = $commandData->input();
        $adminPassword = $input->getOption('admin-password');

        if (strpbrk($adminPassword, '!;$`') !== false) {
            throw new TerminusException("Admin password cannot contain the characters ! ; ` or $ due to a Pantheon platform limitation. Please select a new password.");
        }
    }

    /**
     * Create a new project from the requested source GitHub project.
     * Does the following operations:
     *  - Creates a GitHub repository forked from the source project.
     *  - Creates a Pantheon site to run the tests on.
     *  - Sets up Circle CI to test the repository.
     * In order to use this command, it is also necessary to provide
     * a set of secrets that are used to create the necessary projects,
     * and that are subsequentially cached in Circle CI for use during
     * the test run. Currently, these secrets should be provided via
     * environment variables; this keeps them out of the command history
     * and other places they may be inadvertantly observed.
     *
     *   export GITHUB_TOKEN github_personal_access_token
     *   export CIRCLE_TOKEN circle_personal_api_token
     *
     * Secrets that are not exported will be prompted.
     *
     * @authorize
     *
     * @command build-env:create-project
     * @aliases build:project:create
     * @param string $source Packagist org/name of source template project to fork or path to an existing project on the local filesystem. Paths must either start with ./ or be an absolute path.
     * @param string $target Simple name of project to create.
     * @option org GitHub organization (defaults to authenticated user)
     * @option team Pantheon team
     * @option pantheon-site Name of Pantheon site to create (defaults to 'target' argument)
     * @option email email address to place in ssh-key
     * @option stability Minimum allowed stability for template project.
     * @preserve-local-repository If the source argument is a local directory, then use the local working repository already present in the .git directory.
     */
    public function createProject(
        $source,
        $target = '',
        $options = [
            'org' => '',
            'team' => null,
            'pantheon-site' => '',
            'label' => '',
            'email' => '',
            'test-site-name' => '',
            'admin-password' => '',
            'admin-email' => '',
            'stability' => '',
            'env' => [],
            'preserve-local-repository' => false,
        ])
    {
        $this->warnAboutOldPhp();
        $options = $this->validateOptionsAndSetDefaults($options);

        // Copy options into ordinary variables
        $github_org = $options['org'];
        $site_name = $options['pantheon-site'];
        $team = $options['team'];
        $label = $options['label'];
        $stability = $options['stability'];

        // Provide default values for other optional variables.
        if (empty($label)) {
          $label = $site_name;
        }

        // Get our authenticated credentials from environment variables.
        $github_token = $this->getRequiredGithubToken();
        $circle_token = $this->getRequiredCircleToken();

        // This target label is only used for the log messages below.
        $target_label = $target;
        if (!empty($github_org)) {
            $target_label = "$github_org/$target";
        }

        // Create the github repository
        $this->log()->notice('Create GitHub project {target} from {src}', ['src' => $source, 'target' => $target_label]);
        list($target_project, $siteDir) = $this->createGitHub($source, $target, $github_org, $github_token, $stability, $options);

        $site = null;
        try {
            // Look up our upstream.
            $upstream = $this->autodetectUpstream($siteDir);

            // Push our site to Pantheon.
            $this->log()->notice('Creating site {name} in org {org} with upstream {upstream}', ['name' => $site_name, 'org' => $team, 'upstream' => $upstream]);
            $site = $this->siteCreate($site_name, $label, $upstream, ['org' => $team]);

            // Look up the site UUID for the Pantheon dashboard link
            $siteInfo = $site->serialize();
            $site_uuid = $siteInfo['id'];

            $this->log()->notice('Created a new Pantheon site with UUID {uuid}', ['uuid' => $site_uuid]);

            // Create a new README file to point to this project's Circle tests and the dev site on Pantheon
            $badgeTargetLabel = strtr($target, '-', '_');
            $circleBadge = "[![CircleCI](https://circleci.com/gh/{$target_project}.svg?style=shield)](https://circleci.com/gh/{$target_project})";
            $pantheonBadge = "[![Dashboard {$target}](https://img.shields.io/badge/dashboard-{$badgeTargetLabel}-yellow.svg)](https://dashboard.pantheon.io/sites/{$site_uuid}#dev/code)";
            $siteBadge = "[![Dev Site {$target}](https://img.shields.io/badge/site-{$badgeTargetLabel}-blue.svg)](http://dev-{$target}.pantheonsite.io/)";
            $readme = "# $target\n\n$circleBadge\n$pantheonBadge\n$siteBadge";

            if (!$this->siteHasMultidevCapability($site)) {
                $readme .= "\n\n## IMPORTANT NOTE\n\nAt the time of creation, the Pantheon site being used for testing did not have multidev capability. The test suites were therefore configured to run all tests against the dev environment. If the test site is later given multidev capabilities, you must [visit the CircleCI environment variable configuration page](https://circleci.com/gh/{$target_project}) and delete the environment variable `TERMINUS_ENV`. If you do this, then the test suite will create a new multidev environment for every pull request that is tested.";
            }

            file_put_contents("$siteDir/README.md", $readme);

            // Make the initial commit to our GitHub repository
            $this->log()->notice('Make initial commit');
            $initial_commit = $this->initialCommit($siteDir);
            $this->log()->notice('Push initial commit to GitHub');
            $this->pushToGitHub($github_token, $target_project, $siteDir);

            $this->log()->notice('Push code to Pantheon');

            // Push code to newly-created project.
            $metadata = $this->pushCodeToPantheon("{$site_name}.dev", 'dev', $siteDir);

            // Remove the commit added by pushCodeToPantheon; we don't need the build assets locally any longer.
            $this->resetToCommit($siteDir, $initial_commit);

            $this->log()->notice('Install the site on the dev environment');

            $circle_env = $this->getCIEnvironment($site_name, $options);
            $composer_json = $this->getComposerJson($siteDir);

            // Install the site.
            $site_install_options = [
                'account-mail' => $circle_env['ADMIN_EMAIL'],
                'account-name' => 'admin',
                'account-pass' => $circle_env['ADMIN_PASSWORD'],
                'site-mail' => $circle_env['ADMIN_EMAIL'],
                'site-name' => $circle_env['TEST_SITE_NAME'],
                'site-url' => "https://dev-{$site_name}.pantheonsite.io"
            ];
            $this->doInstallSite("{$site_name}.dev", $composer_json, $site_install_options);

            // Before any tests have been configured, export the
            // configuration set up by the installer.
            $this->exportInitialConfiguration("{$site_name}.dev", $siteDir, $composer_json, $site_install_options);

            // Push our exported configuration to GitHub
            $this->log()->notice('Push exported configuration to GitHub');
            $this->pushToGitHub($github_token, $target_project, $siteDir);

            // Set up CircleCI to test our project.
            $this->configureCircle($target_project, $circle_token, $circle_env);
        }
        catch (\Exception $e) {
            $ch = $this->createGitHubDeleteChannel("repos/$target_project", $github_token);
            $data = $this->execCurlRequest($ch, 'GitHub');
            if (isset($site)) {
                $site->delete();
            }
            throw $e;
        }
        $this->log()->notice('Your new site repository is {github}', ['github' => "https://github.com/$target_project"]);
    }

    /**
     * Fetch the environment variable 'GITHUB_TOKEN', or throw an exception if it is not set.
     * @return string
     */
    protected function getRequiredGithubToken()
    {
        $github_token = getenv('GITHUB_TOKEN');
        if (empty($github_token)) {
            throw new TerminusException("Please generate a GitHub personal access token token, as described in https://help.github.com/articles/creating-an-access-token-for-command-line-use/. Then run: \n\nexport GITHUB_TOKEN=my_personal_access_token_value");
        }
        return $github_token;
    }

    /**
     * Fetch the environment variable 'CIRCLE_TOKEN', or throw an exception if it is not set.
     * @return string
     */
    protected function getRequiredCircleToken()
    {
        $circle_token = getenv('CIRCLE_TOKEN');
        if (empty($circle_token)) {
            throw new TerminusException("Please generate a Circle CI personal API token token, as described in https://circleci.com/docs/api/#authentication. Then run: \n\nexport CIRCLE_TOKEN=my_personal_api_token_value");
        }
        return $circle_token;
    }

    /**
     * Determine whether or not this site can create multidev environments.
     */
    protected function siteHasMultidevCapability($site)
    {
        // Can our new site create multidevs?
        $settings = $site->get('settings');
        if (!$settings) {
            return false;
        }
        return $settings->max_num_cdes > 0;
    }

    /**
     * Return the set of environment variables to save on the CI server.
     *
     * @param string $site_name
     * @param array $options
     * @return array
     */
    public function getCIEnvironment($site_name, $options)
    {
        $site = $this->getSite($site_name);

        $options += [
            'test-site-name' => '',
            'email' => '',
            'admin-password' => '',
            'admin-email' => '',
            'env' => [],
        ];

        $test_site_name = $options['test-site-name'];
        $git_email = $options['email'];
        $admin_password = $options['admin-password'];
        $admin_email = $options['admin-email'];
        $extra_env = $options['env'];

        if (empty($test_site_name)) {
            $test_site_name = $site_name;
        }

        // We should always be authenticated by the time we get here, but
        // we will test just to be sure.
        $terminus_token = $this->recoverSessionMachineToken();
        if (empty($terminus_token)) {
            throw new TerminusException("Please generate a Pantheon machine token, as described in https://pantheon.io/docs/machine-tokens/. Then log in via: \n\nterminus auth:login --machine-token=my_machine_token_value");
        }

        // Set up Circle CI and run our first test.
        $circle_env = [
            'TERMINUS_TOKEN' => $terminus_token,
            'TERMINUS_SITE' => $site_name,
            'TEST_SITE_NAME' => $test_site_name,
            'ADMIN_PASSWORD' => $admin_password,
            'ADMIN_EMAIL' => $admin_email,
            'GIT_EMAIL' => $git_email,
        ];
        // If this site cannot create multidev environments, then configure
        // it to always run tests on the dev environment.
        if (!$this->siteHasMultidevCapability($site)) {
            $circle_env['TERMINUS_ENV'] = 'dev';
        }

        // Add the github token if available
        $github_token = getenv('GITHUB_TOKEN');
        if ($github_token) {
            $circle_env['GITHUB_TOKEN'] = $github_token;
        }

        // Add in extra environment provided on command line via
        // --env='key=value' --env='another=v2'
        foreach ($extra_env as $env) {
            list($key, $value) = explode('=', $env, 2) + ['',''];
            if (!empty($key) && !empty($value)) {
                $circle_env[$key] = $value;
            }
        }

        return $circle_env;
    }

    /**
     * Check to see if common options are valid. Provide sensible defaults
     * where values are unspecified.
     */
    protected function validateOptionsAndSetDefaults($options)
    {
        if (empty($options['admin-password'])) {
            $options['admin-password'] = mt_rand();
        }

        if (empty($options['email'])) {
            $options['email'] = exec('git config user.email');
        }

        if (empty($options['admin-email'])) {
            $options['admin-email'] = $options['email'];
        }

        // Catch errors in email address syntax
        $this->validateEmail('email', $options['email']);
        $this->validateEmail('admin-email', $options['admin-email']);

        return $options;
    }

    /**
     * Check to see if the provided email address is valid.
     */
    protected function validateEmail($emailOptionName, $emailValue)
    {
        // http://www.regular-expressions.info/email.html
        if (preg_match('/^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,63}$/i', $emailValue)) {
            return;
        }

        throw new TerminusException("The email address '{email}'' is not valid. Please set a valid email address via 'git config --global user.email <address>', or override this setting with the --{option} option.", ['email' => $emailValue, 'option' => $emailOptionName]);
    }

    /**
     * Write the CI environment variables to the Circle "envrionment variables" configuration section.
     *
     * @param string $target_project
     * @param string $circle_token
     * @param array $circle_env
     */
    public function configureCircle($target_project, $circle_token, $circle_env)
    {
        $this->log()->notice('Configure Circle CI');

        $site_name = $circle_env['TERMINUS_SITE'];
        $git_email = $circle_env['GIT_EMAIL'];
        $target_label = strtr($target_project, '/', '-');

        $circle_url = "https://circleci.com/api/v1.1/project/github/$target_project";
        $this->setCircleEnvironmentVars($circle_url, $circle_token, $circle_env);

        // Create an ssh key pair dedicated to use in these tests.
        // Change the email address to "user+ci-SITE@domain.com" so
        // that these keys can be differentiated in the Pantheon dashboard.
        $ssh_key_email = str_replace('@', "+ci-{$target_label}@", $git_email);
        $this->log()->notice('Create ssh key pair for {email}', ['email' => $ssh_key_email]);
        list($publicKey, $privateKey) = $this->createSshKeyPair($ssh_key_email, $site_name . '-key');
        $this->addPublicKeyToPantheonUser($publicKey);
        $this->addPrivateKeyToCircleProject($circle_url, $circle_token, $privateKey);

        // Follow the project (start a build)
        $this->circleFollow($circle_url, $circle_token);
    }

    /**
     * Configure CI Tests for a Pantheon site created from the specified
     * GitHub repository
     *
     * @authorize
     *
     * @command build-env:ci:configure
     * @aliases build:ci:configure
     * @param $site_name The pantheon site to test.
     * @param $target_project The GitHub org/project to build the Pantheon site from.
     */
    public function configureCI(
        $site_name,
        $target_project,
        $options = [
            'test-site-name' => '',
            'email' => '',
            'admin-password' => '',
            'admin-email' => '',
            'env' => [],
        ])
    {
        $site = $this->getSite($site_name);
        $options = $this->validateOptionsAndSetDefaults($options);

        // Get the build metadata from the Pantheon site. Fail if there is
        // no build metadata on the master branch of the Pantheon site.
        $buildMetadata = $this->retrieveBuildMetadata("{$site_name}.dev") + ['url' => ''];
        $desired_url = "git@github.com:{$target_project}.git";
        if (!empty($buildMetadata['url']) && ($desired_url != $buildMetadata['url'])) {
            throw new TerminusException('The site {site} is already configured to test {url}; you cannot use this site to test {desired}.', ['site' => $site_name, 'url' => $buildMetadata['url'], 'desired' => $desired_url]);
        }

        // Get our authenticated credentials from environment variables.
        $github_token = $this->getRequiredGithubToken();
        $circle_token = $this->getRequiredCircleToken();

        $circle_env = $this->getCIEnvironment($site_name, $options);
        $this->configureCircle($target_project, $circle_token, $circle_env);
    }

    /**
     * Provide some simple aliases to reduce typing when selecting common repositories
     */
    protected function expandSourceAliases($source)
    {
        //
        // key:   org/project of template repository
        // value: list of aliases
        //
        // If the org is missing from the template repository, then
        // pantheon-systems is assumed.
        //
        $aliases = [
            'example-drops-8-composer' => ['d8', 'drops-8'],
            'example-drops-7-composer' => ['d7', 'drops-7'],

            // The wordpress template has not been created yet
            // 'example-wordpress-composer' => ['wp', 'wordpress'],
        ];

        $map = [strtolower($source) => $source];
        foreach ($aliases as $full => $shortcuts) {
            foreach ($shortcuts as $alias) {
                $map[$alias] = $full;
            }
        }

        return $map[strtolower($source)];
    }

    /**
     * Detect the upstream to use based on the contents of the source repository.
     * Upstream is irrelevant, save for the fact that this is the only way to
     * set the framework on Pantheon at the moment.
     */
    protected function autodetectUpstream($siteDir)
    {
        $upstream = $this->autodetectUpstreamAtDir("$siteDir/web");
        if ($upstream) {
            return $upstream;
        }
        $upstream = $this->autodetectUpstreamAtDir($siteDir);
        if ($upstream) {
            return $upstream;
        }
        // Can't tell? Assume Drupal 8.
        return 'empty';
    }

    protected function autodetectUpstreamAtDir($siteDir)
    {
        $upstream_map = [
          'core/misc/drupal.js' => 'empty', // Drupal 8
          'misc/drupal.js' => 'empty-7', // Drupal 7
          'wp-config.php' => 'empty-wordpress', // WordPress
        ];

        foreach ($upstream_map as $file => $upstream) {
            if (file_exists("$siteDir/$file")) {
                return $upstream;
            }
        }

        return false;
    }

    /**
     * Create a unique ssh key pair to use in testing
     *
     * @param string $ssh_key_email
     * @param string $prefix
     * @return [string, string]
     */
    protected function createSshKeyPair($ssh_key_email, $prefix = 'id')
    {
        $tmpkeydir = $this->tempdir('ssh-keys');

        $privateKey = "$tmpkeydir/$prefix";
        $publicKey = "$privateKey.pub";

        $this->passthru("ssh-keygen -t rsa -b 4096 -f $privateKey -N '' -C '$ssh_key_email'");

        return [$publicKey, $privateKey];
    }

    /**
     * Use the GitHub API to create a new GitHub project.
     */
    protected function createGitHub($source, $target, $github_org, $github_token, $stability = '', $options = [])
    {
        // We need a different URL here if $github_org is an org; if no
        // org is provided, then we use a simpler URL to create a repository
        // owned by the currently-authenitcated user.
        $createRepoUrl = "orgs/$github_org/repos";
        $target_org = $github_org;
        if (empty($github_org)) {
            $createRepoUrl = 'user/repos';
            $userData = $this->curlGitHub('user', [], $github_token);
            $target_org = $userData['login'];
        }
        $target_project = "$target_org/$target";

        $tmpsitedir = $this->tempdir('local-site');
        if (is_dir($source)) {
            if ($options['preserve-local-repository']) {
                if (!is_dir("$source/.git")) {
                    throw new TerminusException('Specified --preserve-local-repository, but the directory {source} does not contains a .git directory.', compact('$source'));
                }
            }
            else {
                if (is_dir("$source/.git")) {
                    throw new TerminusException('The directory {source} already contains a .git directory. Use --preserve-local-repository if you wish to use this existing repository.', compact('$source'));
                }
            }
            $local_site_path = $source;
        }
        else {
            $source_project = $this->sourceProjectFromSource($source);

            $this->log()->notice('Creating project and resolving dependencies.');

            // If the source is 'org/project:dev-branch', then automatically
            // set the stability to 'dev'.
            if (empty($stability) && preg_match('#:dev-#', $source)) {
                $stability = 'dev';
            }
            // Pass in --stability to `composer create-project` if user requested it.
            $stability_flag = empty($stability) ? '' : "--stability $stability";

            $this->passthru("composer create-project --working-dir=$tmpsitedir $source $target -n $stability_flag");

            $local_site_path = "$tmpsitedir/$target";
        }

        // Create a GitHub repository
        $this->log()->notice('Creating repository {repo} from {source}', ['repo' => $target_project, 'source' => $source]);
        $postData = ['name' => $target];
        $result = $this->curlGitHub($createRepoUrl, $postData, $github_token);

        // Create a git repository. Add an origin just to have the data there
        // when collecting the build metadata later. We use the 'pantheon'
        // remote when pushing.
        // TODO: Do we need to remove $local_site_path/.git? (-n in create-project should obviate this need)
        if (!is_dir("$local_site_path/.git")) {
            $this->passthru("git -C $local_site_path init");
        }
        $this->passthru("git -C $local_site_path remote add origin 'git@github.com:{$target_project}.git'");

        return [$target_project, $local_site_path];
    }

    /**
     * Given a source, such as:
     *    pantheon-systems/example-drops-8-composer:dev-lightning-fist-2
     * Return the 'project' portion, including the org, e.g.:
     *    pantheon-systems/example-drops-8-composer
     */
    protected function sourceProjectFromSource($source)
    {
        return preg_replace('/:.*/', '', $source);
    }

    /**
     * Make the initial commit to our new GitHub project.
     */
    protected function initialCommit($repositoryDir)
    {
        // Add the canonical repository files to the new GitHub project
        // respecting .gitignore.
        $this->passthru("git -C $repositoryDir add .");
        $this->passthru("git -C $repositoryDir commit -m 'Initial commit'");
        return $this->getHeadCommit($repositoryDir);
    }

    /**
     * Return the sha of the HEAD commit.
     */
    protected function getHeadCommit($repositoryDir)
    {
        return exec("git -C $repositoryDir rev-parse HEAD");
    }

    /**
     * Reset to the specified commit (or remove the last commit)
     */
    protected function resetToCommit($repositoryDir, $resetToCommit = 'HEAD^')
    {
        $this->passthru("git -C $repositoryDir reset --hard $resetToCommit");
    }

    /**
     * Make the initial commit to our new GitHub project.
     */
    protected function pushToGitHub($github_token, $target_project, $repositoryDir)
    {
        $remote_url = "https://$github_token:x-oauth-basic@github.com/${target_project}.git";
        $this->passthruRedacted("git -C $repositoryDir push --progress $remote_url master", $github_token);
    }

    // TODO: if we could look up the commandfile for
    // Pantheon\Terminus\Commands\Site\CreateCommand,
    // then we could just call its 'create' method
    public function siteCreate($site_name, $label, $upstream_id, $options = ['org' => null,])
    {
        if ($this->sites()->nameIsTaken($site_name)) {
            throw new TerminusException('The site name {site_name} is already taken.', compact('site_name'));
        }

        $workflow_options = [
            'label' => $label,
            'site_name' => $site_name
        ];
        $user = $this->session()->getUser();

        // Locate upstream
        $upstream = $user->getUpstreams()->get($upstream_id);

        // Locate organization
        if (!empty($org_id = $options['org'])) {
            $org = $user->getOrgMemberships()->get($org_id)->getOrganization();
            $workflow_options['organization_id'] = $org->id;
        }

        // Create the site
        $this->log()->notice('Creating a new Pantheon site {name}', ['name' => $site_name]);
        $workflow = $this->sites()->create($workflow_options);
        while (!$workflow->checkProgress()) {
            // @TODO: Add Symfony progress bar to indicate that something is happening.
        }

        // Deploy the upstream
        if ($site = $this->getSite($workflow->get('waiting_for_task')->site_id)) {
            $this->log()->notice('Deploying {upstream} to Pantheon site', ['upstream' => $upstream_id]);
            $workflow = $site->deployProduct($upstream->id);
            while (!$workflow->checkProgress()) {
                // @TODO: Add Symfony progress bar to indicate that something is happening.
            }
            $this->log()->notice('Deployed CMS');
        }

        return $this->getSite($site_name);
    }

    /**
     * Destroy a Pantheon site that was created by the build-env:create-project command.
     *
     * @command build-env:obliterate
     * @aliases build:env:obliterate
     */
    public function obliterate($site_name)
    {
        $site = $this->getSite($site_name);

        // Get the build metadata from the Pantheon site. Fail if there is
        // no build metadata on the master branch of the Pantheon site.
        $buildMetadata = $this->retrieveBuildMetadata("{$site_name}.dev") + ['url' => ''];
        if (empty($buildMetadata['url'])) {
            throw new TerminusException('The site {site} was not created with the build-env:create-project command; it therefore cannot be deleted via build-env:obliterate.', ['site' => $site_name]);
        }
        $github_url = $buildMetadata['url'];

        // Look up the GitHub authentication token
        $github_token = $this->getRequiredGithubToken();

        // Do nothing without confirmation
        if (!$this->confirm('Are you sure you want to delete {site} AND its corresponding GitHub repository {github_url} and CircleCI configuration?', ['site' => $site->getName(), 'github_url' => $github_url])) {
            return;
        }

        $this->log()->notice('About to delete {site} and its corresponding GitHub repository {github_url} and CircleCI configuration.', ['site' => $site->getName(), 'github_url' => $github_url]);

        // We don't need to do anything with CircleCI; the project is
        // automatically removed when the GitHub project is deleted.

        // Use the GitHub API to delete the GitHub project.
        $project = $this->projectFromRemoteUrl($github_url);
        $ch = $this->createGitHubDeleteChannel("repos/$project", $github_token);
        $data = $this->execCurlRequest($ch, 'GitHub');

        // GitHub oddity: if DELETE fails, the message is set,
        // but 'errors' is not set. Force an error in this case.
        if (isset($data['message'])) {
            throw new TerminusException('GitHub error: {message}.', ['message' => $data['message']]);
        }

        $this->log()->notice('Deleted {project} from GitHub', ['project' => $project,]);

        // Use the Terminus API to delete the Pantheon site.
        $site->delete();
        $this->log()->notice('Deleted {site} from Pantheon', ['site' => $site_name,]);
    }

    /**
     * Create the specified multidev environment on the given Pantheon
     * site from the build assets at the current working directory.
     *
     * @command build-env:create
     * @aliases build:env:create
     * @param string $site_env_id The site and env of the SOURCE
     * @param string $multidev The name of the env to CREATE
     * @option label What to name the environment in commit comments
     * @option clone-content Run terminus env:clone-content if the environment is re-used
     * @option db-only Only clone the database when runing env:clone-content
     * @option notify Command to exec to notify when a build environment is created
     */
    public function createBuildEnv(
        $site_env_id,
        $multidev,
        $options = [
            'label' => '',
            'clone-content' => false,
            'db-only' => false,
            'notify' => '',
        ])
    {
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $env_id = $env->getName();
        $env_label = $multidev;
        if (!empty($options['label'])) {
            $env_label = $options['label'];
        }

        // Fetch the site id also
        $siteInfo = $site->serialize();
        $site_id = $siteInfo['id'];

        // Check to see if '$multidev' already exists on Pantheon.
        $environmentExists = $site->getEnvironments()->has($multidev);

        // Check to see if we should create before pushing or after
        $createBeforePush = $this->commitChangesFile('HEAD', 'pantheon.yml');

        if (!$environmentExists && $createBeforePush) {
            // If pantheon.yml exists, then we need to create the environment
            // in advance, before we push our change. It is more
            // efficient to push the branch first, and then create
            // the multidev, as in this instance, we do not need
            // to call waitForCodeSync(). However, changes to pantheon.yml
            // will not be applied unless we push our change.
            // To allow pantheon.yml to be processed, we will
            // create the multidev environment, and then push the code.
            $this->create($site_env_id, $multidev);
        }

        $metadata = $this->pushCodeToPantheon($site_env_id, $multidev, '', $env_label);

        // Create a new environment for this test.
        if (!$environmentExists && !$createBeforePush) {
            // If the environment is created after the branch is pushed,
            // then there is never a race condition -- the new env is
            // created with the correct files from the specified branch.
            $this->create($site_env_id, $multidev);
        }

        // Clear the environments, so that they will be re-fetched.
        // Otherwise, the new environment will not be found immediately
        // after it is first created. If we set the connection mode to
        // git mode, then Terminus will think it is still in sftp mode
        // if we do not re-fetch.
        // TODO: Require Terminus ^1.1 in our composer.json and simplify old code below.
        if (method_exists($site, 'unsetEnvironments')) {
            $site->unsetEnvironments();
        }
        else {
            // In Terminus 1.0.0, Site::unsetEnvironments() did not exist,
            // and $site->environments was public. If the line below is crashing
            // for you, perhaps you are using a dev version of Terminus from
            // 20 Feb - 7 Mar 2017. Use something newer or older instead.
            $site->environments = null;
        }

        // Get (or re-fetch) a reference to our target multidev site.
        $target = $site->getEnvironments()->get($multidev);

        // If we did not create our environment, then run clone-content
        // instead -- but only if requested. No point in running 'clone'
        // if the user plans on re-installing Drupal.
        if ($environmentExists && $options['clone-content']) {
            $this->cloneContent($target, $env_id, $options['db-only']);
        }

        // Set the target environment to sftp mode
        $this->connectionSet($target, 'sftp');

        // If '--notify' was passed, then exec the notify command
        if (!empty($options['notify'])) {
            $site_name = $site->getName();
            $project = $this->projectFromRemoteUrl($metadata['url']);
            $metadata += [
                'project' => $project,
                'site-id' => $site_id,
                'site' => $site_name,
                'env' => $multidev,
                'label' => $env_label,
                'dashboard-url' => "https://dashboard.pantheon.io/sites/{$site_id}#{$multidev}",
                'site-url' => "https://{$multidev}-{$site_name}.pantheonsite.io/",
            ];

            $command = $this->interpolate($options['notify'], $metadata);

            // Run notification command. Ignore errors.
            passthru($command);
        }
    }

    /**
     * Run an env:clone-content operation
     * @param Pantheon\Terminus\Models\Environment $target
     * @param string $from_name
     * @param bool $db_only
     * @param bool $files_only
     */
    public function cloneContent($target, $from_name, $db_only = false, $files_only = false)
    {
        // Clone files if we're only doing files, or if "only do db" is not set.
        if ($files_only || !$db_only) {
            $workflow = $target->cloneFiles($from_name);
            $this->log()->notice(
                "Cloning files from {from_name} environment to {target_env} environment",
                ['from_name' => $from_name, 'target_env' => $target->getName()]
            );
            while (!$workflow->checkProgress()) {
                // @TODO: Add Symfony progress bar to indicate that something is happening.
            }
            $this->log()->notice($workflow->getMessage());
        }

        // Clone database if we're only doing the database, or if "only do files" is not set.
        if ($db_only || !$files_only) {
            $workflow = $target->cloneDatabase($from_name);
            $this->log()->notice(
                "Cloning database from {from_name} environment to {target_env} environment",
                ['from_name' => $from_name, 'target_env' => $target->getName()]
            );
            while (!$workflow->checkProgress()) {
                // @TODO: Add Symfony progress bar to indicate that something is happening.
            }
            $this->log()->notice($workflow->getMessage());
        }
    }

    /**
     * Install the apporpriate CMS on the newly-created Pantheon site.
     *
     * @command build-env:site-install
     * @aliases build:env:install
     */
    public function installSite(
        $site_env_id,
        $siteDir = '',
        $site_install_options = [
            'account-mail' => '',
            'account-name' => '',
            'account-pass' => '',
            'site-mail' => '',
            'site-name' => ''
        ])
    {
        if (empty($siteDir)) {
            $siteDir = getcwd();
        }
        $composer_json = $this->getComposerJson($siteDir);
        return $this->doInstallSite($site_env_id, $composer_json, $site_install_options);
    }

    protected function doInstallSite(
        $site_env_id,
        $composer_json = [],
        $site_install_options = [
            'account-mail' => '',
            'account-name' => '',
            'account-pass' => '',
            'site-mail' => '',
            'site-name' => ''
        ])
    {
        $command_template = $this->getInstallCommandTemplate($composer_json);
        return $this->runCommandTemplateOnRemoteEnv($site_env_id, $command_template, "Install site", $site_install_options);
    }

    protected function runCommandTemplateOnRemoteEnv(
        $site_env_id,
        $command_templates,
        $operation_label,
        $options
    ) {
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $this->log()->notice('{op} on {site}', ['op' => $operation_label, 'site' => $site_env_id]);

        // Set the target environment to sftp mode prior to running the command
        $this->connectionSet($env, 'sftp');

        foreach ((array)$command_templates as $command_template) {
            $metadata = array_map(function ($item) { return $this->escapeArgument($item); }, $options);
            $command_line = $this->interpolate($command_template, $metadata);
            $redacted_metadata = $this->redactMetadata($metadata, ['account-pass']);
            $redacted_command_line = $this->interpolate($command_template, $redacted_metadata);

            $this->log()->notice(' - {cmd}', ['cmd' => $redacted_command_line]);
            $result = $env->sendCommandViaSsh(
                $command_line,
                function ($type, $buffer) {
                }
            );
            $output = $result['output'];
            if ($result['exit_code']) {
                throw new TerminusException('{op} failed with exit code {status}', ['op' => $operation_label, 'status' => $result['exit_code']]);
            }
        }
    }

    protected function exportInitialConfiguration($site_env_id, $repositoryDir, $composer_json, $options)
    {
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $command_template = $this->getExportConfigurationTemplate($composer_json);
        if (empty($command_template)) {
            return;
        }

        // Run the 'export configuration' command
        $this->runCommandTemplateOnRemoteEnv($site_env_id, $command_template, "Export configuration", $options);

        // Commit the changes. Quicksilver is not set up to push these back
        // to GitHub from the dev branch, but we don't want to leave these changes
        // uncommitted.
        $env->commitChanges('Install site and export configuration.');

        // TODO: How do we know where the configuration will be exported to?
        // Perhaps we need to export to a temporary directory where we control
        // the path. Perhaps export to ':tmp/config' instead of ':code/config'.
        $this->rsync($site_env_id, ':code/config', $repositoryDir);

        $this->passthru("git -C $repositoryDir add config");
        $this->passthru("git -C $repositoryDir commit -m 'Export configuration'");
    }

    /**
     * Remove sensitive information from a metadata array.
     */
    protected function redactMetadata($metadata, $keys_to_redact)
    {
        foreach ($keys_to_redact as $key) {
            $metadata[$key] = '"[REDACTED]"';
        }
        return $metadata;
    }

    /**
     * Determine the command to use to install the site.
     */
    protected function getInstallCommandTemplate($composer_json)
    {
        if (isset($composer_json['extra']['build-env']['install-cms'])) {
            return $composer_json['extra']['build-env']['install-cms'];
        }
        // TODO: Select a different default template based on the cms type (Drupal or WordPress).
        $defaultTemplate = 'drush site-install --yes --account-mail={account-mail} --account-name={account-name} --account-pass={account-pass} --site-mail={site-mail} --site-name={site-name}';

        return $defaultTemplate;
    }

    /**
     * Determine the command to use to export configuration.
     */
    protected function getExportConfigurationTemplate($composer_json)
    {
        if (isset($composer_json['extra']['build-env']['export-configuration'])) {
            return $composer_json['extra']['build-env']['export-configuration'];
        }

        return '';
    }

    /**
     * Read the composer.json file from the provided site directory.
     */
    protected function getComposerJson($siteDir)
    {
        $composer_json_file = "$siteDir/composer.json";
        if (!file_exists($composer_json_file)) {
            return [];
        }
        return json_decode(file_get_contents($composer_json_file), true);
    }

    /**
     * Escape one command-line arg
     *
     * @param string $arg The argument to escape
     * @return RowsOfFields
     */
    protected function escapeArgument($arg)
    {
        // Omit escaping for simple args.
        if (preg_match('/^[a-zA-Z0-9_-]*$/', $arg)) {
            return $arg;
        }
        return ProcessUtils::escapeArgument($arg);
    }

    /**
     * Push code to a specific Pantheon site and environment that already exists.
     *
     * @command build-env:push-code
     * @aliases build:env:push
     *
     * @param string $site_env_id Site and environment to push to. May be any dev or multidev environment.
     * @param string $repositoryDir Code to push. Defaults to cwd.
     */
    public function pushCode(
        $site_env_id,
        $repositoryDir = '',
        $options = [
          'label' => '',
        ])
    {
        return $this->pushCodeToPantheon($site_env_id, '', $repositoryDir, $options['label']);
    }

    /**
     * Push code to Pantheon -- common routine used by 'create-project', 'create' and 'push-code' commands.
     */
    public function pushCodeToPantheon(
        $site_env_id,
        $multidev = '',
        $repositoryDir = '',
        $label = '')
    {
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $env_id = $env->getName();
        $multidev = empty($multidev) ? $env_id : $multidev;
        $branch = ($multidev == 'dev') ? 'master' : $multidev;
        $env_label = $multidev;
        if (!empty($label)) {
            $env_label = $label;
        }

        if (empty($repositoryDir)) {
            $repositoryDir = getcwd();
        }

        // Sanity check: only push from directories that have .git and composer.json
        // Note: we might want to use push-to-pantheon even if there isn't a composer.json,
        // e.g. when using build:env:create with drops-7.
        foreach (['.git'] as $item) {
            if (!file_exists("$repositoryDir/$item")) {
                throw new TerminusException('Cannot push from {dir}: missing {item}.', ['dir' => $repositoryDir, 'item' => $item]);
            }
        }

        $this->log()->notice('Pushing code to {multidev} using branch {branch}.', ['multidev' => $multidev, 'branch' => $branch]);

        // Fetch the site id also
        $siteInfo = $site->serialize();
        $site_id = $siteInfo['id'];

        // Check to see if '$multidev' already exists on Pantheon.
        $environmentExists = $site->getEnvironments()->has($multidev);

        // Add a remote named 'pantheon' to point at the Pantheon site's git repository.
        // Skip this step if the remote is already there (e.g. due to CI service caching).
        $this->addPantheonRemote($env, $repositoryDir);
        // $this->passthru("git -C $repositoryDir fetch pantheon");

        // Record the metadata for this build
        $metadata = $this->getBuildMetadata($repositoryDir);
        $this->recordBuildMetadata($metadata, $repositoryDir);

        // Drupal 7: Drush requires a settings.php file. Add one to the
        // build results if one does not already exist.
        $default_dir = "$repositoryDir/" . (is_dir("$repositoryDir/web") ? 'web/sites/default' : 'sites/default');
        $settings_file = "$default_dir/settings.php";
        if (is_dir($default_dir) && !is_file($settings_file)) {
          file_put_contents($settings_file, "<?php\n");
          $this->log()->notice('Created empty settings.php file {settingsphp}.', ['settingsphp' => $settings_file]);
        }

        // Remove any .git directories added by composer from the set of files
        // being committed. Ideally, there will be none. We cannot allow any to
        // remain, though, as git will interpret these as submodules, which
        // will prevent the contents of directories containing .git directories
        // from being added to the main repository.
        $finder = new Finder();
        $fs = new Filesystem();
        $fs->remove(
          $finder
            ->directories()
            ->in("$repositoryDir")
            ->ignoreDotFiles(false)
            ->ignoreVCS(false)
            ->depth('> 0')
            ->name('.git')
            ->getIterator()
        );

        // Create a new branch and commit the results from anything that may
        // have changed. We presume that the source repository is clean of
        // any unwanted files prior to the build step (e.g. after a clean
        // checkout in a CI environment.)
        $this->passthru("git -C $repositoryDir checkout -B $branch");
        $this->passthru("git -C $repositoryDir add --force -A .");

        // Now that everything is ready, commit the build artifacts.
        $this->passthru("git -C $repositoryDir commit -q -m 'Build assets for $env_label.'");

        // If the environment does exist, then we need to be in git mode
        // to push the branch up to the existing multidev site.
        if ($environmentExists) {
            $target = $site->getEnvironments()->get($multidev);
            $this->connectionSet($target, 'git');
        }

        // Push the branch to Pantheon
        $preCommitTime = time();
        $this->passthru("git -C $repositoryDir push --force -q pantheon $branch");

        // If the environment already existed, then we risk encountering
        // a race condition, because the 'git push' above will fire off
        // an asynchronous update of the existing update. If we switch to
        // sftp mode before this sync is completed, then the converge that
        // sftp mode kicks off will corrupt the environment.
        if ($environmentExists) {
            $this->waitForCodeSync($preCommitTime, $site, $multidev);
        }

        return $metadata;
    }

    protected function projectFromRemoteUrl($url)
    {
        return preg_replace('#[^:/]*[:/]([^/:]*/[^.]*)\.git#', '\1', str_replace('https://', '', $url));
    }

    /**
     * @command build-env:merge
     * @aliases build:env:merge
     * @param string $site_env_id The site and env to merge and delete
     * @option label What to name the environment in commit comments
     * @option delete Delete the multidev environment after merging.
     */
    public function mergeBuildEnv($site_env_id, $options = ['label' => '', 'delete' => false])
    {
        // c.f. merge-pantheon-multidev script
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $env_id = $env->getName();
        $env_label = $env_id;
        if (!empty($options['label'])) {
            $env_label = $options['label'];
        }

        // If we are building against the 'dev' environment, then simply
        // commit the changes once the PR is merged.
        if ($env_id == 'dev') {
            $env->commitChanges("Build assets for $env_label.");
            return;
        }

        $preCommitTime = time();

        // When using build-env:merge, we expect that the dev environment
        // should stay in git mode. We will switch it to git mode now to be sure.
        $dev_env = $site->getEnvironments()->get('dev');
        $this->connectionSet($dev_env, 'git');

        // Branch name to use for temporary work when merging
        $tmpWorkBranch = 'temp-work-' . $env_id;

        // Replace the entire contents of the master branch with the branch we just tested.
        $this->passthru('git fetch pantheon');
        $this->passthru('git checkout pantheon/' . $env_id);
        $this->passthru("git checkout -B $tmpWorkBranch");

        // Push our changes back to the dev environment, replacing whatever was there before.
        $this->passthru("git push --force -q pantheon $tmpWorkBranch:master");
        passthru("git branch -D $tmpWorkBranch");

        // Wait for the dev environment to finish syncing after the merge.
        $this->waitForCodeSync($preCommitTime, $site, 'dev');

        // Once the build environment is merged, delete it if we don't need it any more
        if ($options['delete']) {
            $this->deleteEnv($env, true);
        }
    }

    /**
     * Delete all of the build environments matching the provided pattern,
     * optionally keeping a few of the most recently-created. Also, optionally
     * any environment that still has a remote branch on GitHub may be preserved.
     *
     * @command build-env:delete
     * @aliases build:env:delete
     *
     * @param string $site_id Site name
     * @param string $multidev_delete_pattern Pattern used for build environments
     * @option keep Number of environments to keep
     * @option preserve-prs Keep any environment that still has an open pull request associated with it.
     * @option preserve-if-branch Keep any environment that still has a remote branch that has not been deleted.
     * @option delete-branch Delete the git branch in the Pantheon repository in addition to the multidev environment.
     * @option dry-run Only print what would be deleted; do not delete anything.
     *
     * @deprecated This function can be too destructive if called from ci
     * using --yes with an overly-inclusive delete pattern, e.g. if an
     * environment variable for a recurring build is incorrectly altered.
     * Use build-env:delete:ci and build-env:delete:pr as safer alternatives.
     * This function will be removed in future versions.
     */
    public function deleteBuildEnv(
        $site_id,
        $multidev_delete_pattern = self::DEFAULT_DELETE_PATTERN,
        $options = [
            'keep' => 0,
            'preserve-prs' => false,
            'preserve-if-branch' => false,
            'delete-branch' => false,
            'dry-run' => false,
        ])
    {
        // Look up the oldest environments matching the delete pattern
        $oldestEnvironments = $this->oldestEnvironments($site_id, $multidev_delete_pattern);

        // Stop if nothing matched
        if (empty($oldestEnvironments)) {
            $this->log()->notice('No environments matched the provided pattern "{pattern}".', ['pattern' => $multidev_delete_pattern]);
            return;
        }

        // Reduce result list down to just the env id ('ci-123' et. al.)
        $oldestEnvironments = array_map(
            function ($item) {
                return $item['id'];
            },
            $oldestEnvironments
        );

        // Find the URL to the remote origin
        $remoteUrlFromGit = exec('git config --get remote.origin.url');

        // Find the URL of the remote origin stored in the build metadata
        $remoteUrl = $this->retrieveRemoteUrlFromBuildMetadata($site_id, $oldestEnvironments);

        // Bail if there is a URL mismatch
        if (!empty($remoteUrlFromGit) && ($remoteUrlFromGit != $remoteUrl)) {
            throw new TerminusException('Remote repository mismatch: local repository, {gitrepo} is different than the repository {metadatarepo} associated with the site {site}.', ['gitrepo' => $remoteUrlFromGit, 'metadatarepo' => $remoteUrl, 'site' => $site_id]);
        }

        // Reduce result list down to just those that do NOT have open PRs.
        // We will use either the GitHub API or available git branches to check.
        $environmentsWithoutPRs = [];
        if (!empty($options['preserve-prs'])) {
            $github_token = getenv('GITHUB_TOKEN');
            // Call GitHub PR to get all open PRs.  Filter out matching branches
            // from this list that appear in $oldestEnvironments
            $environmentsWithoutPRs = $this->preserveEnvsWithOpenPRs($remoteUrl, $oldestEnvironments, $multidev_delete_pattern, $github_token);
        }
        elseif (!empty($options['preserve-if-branch'])) {
            $environmentsWithoutPRs = $this->preserveEnvsWithGitHubBranches($oldestEnvironments, $multidev_delete_pattern);
        }
        $environmentsToKeep = array_diff($oldestEnvironments, $environmentsWithoutPRs);
        $oldestEnvironments = $environmentsWithoutPRs;

        // Separate list into 'keep' and 'oldest' lists.
        if ($options['keep']) {
            $environmentsToKeep = array_merge(
                $environmentsToKeep,
                array_slice($oldestEnvironments, count($oldestEnvironments) - $options['keep'])
            );
            $oldestEnvironments = array_slice($oldestEnvironments, 0, count($oldestEnvironments) - $options['keep']);
        }

        // Make a display message of the environments to delete and keep
        $deleteList = implode(',', $oldestEnvironments);
        $keepList = implode(',', $environmentsToKeep);
        if (empty($keepList)) {
            $keepList = 'none of the build environments';
        }

        // Stop if there is nothing to delete.
        if (empty($oldestEnvironments)) {
            $this->log()->notice('Nothing to delete. Keeping {keepList}.', ['keepList' => $keepList,]);
            return;
        }

        if ($options['dry-run']) {
            $this->log()->notice('Dry run: would delete {deleteList} and keep {keepList}', ['deleteList' => $deleteList, 'keepList' => $keepList]);
            return;
        }

        if (!$this->confirm('Are you sure you want to delete {deleteList} and keep {keepList}?', ['deleteList' => $deleteList, 'keepList' => $keepList])) {
            return;
        }

        // Delete each of the selected environments.
        foreach ($oldestEnvironments as $env_id) {
            $site_env_id = "{$site_id}.{$env_id}";

            list (, $env) = $this->getSiteEnv($site_env_id);
            $this->deleteEnv($env, $options['delete-branch']);
        }
    }

    protected function preserveEnvsWithOpenPRs($remoteUrl, $oldestEnvironments, $multidev_delete_pattern, $auth = '')
    {
        $project = $this->projectFromRemoteUrl($remoteUrl);
        $branchList = $this->branchesForOpenPullRequests($project, $auth);
        return $this->filterBranches($oldestEnvironments, $branchList, $multidev_delete_pattern);
    }

    function branchesForOpenPullRequests($project, $auth = '')
    {
        $data = $this->curlGitHub("repos/$project/pulls?state=open", [], $auth);

        $branchList = array_map(
            function ($item) {
                return $item['head']['ref'];
            },
            $data
        );

        return $branchList;
    }

    protected function createAuthorizationHeaderCurlChannel($url, $auth = '')
    {
        $headers = [
            'Content-Type: application/json',
            'User-Agent: pantheon/terminus-build-tools-plugin'
        ];

        if (!empty($auth)) {
            $headers[] = "Authorization: token $auth";
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        return $ch;
    }

    protected function createBasicAuthenticationCurlChannel($url, $username, $password = '')
    {
        $ch = $this->createAuthorizationHeaderCurlChannel($url);
        curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);
        return $ch;
    }

    protected function setCurlChannelPostData($ch, $postData, $force = false)
    {
        if (!empty($postData) || $force) {
            $payload = json_encode($postData);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
    }

    public function execCurlRequest($ch, $service = 'API request')
    {
        $result = curl_exec($ch);
        if(curl_errno($ch))
        {
            throw new TerminusException(curl_error($ch));
        }
        $data = json_decode($result, true);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $errors = [];
        if (isset($data['errors'])) {
            foreach ($data['errors'] as $error) {
                $errors[] = $error['message'];
            }
        }
        if ($httpCode && ($httpCode >= 300)) {
            $errors[] = "Http status code: $httpCode";
        }

        $message = isset($data['message']) ? "{$data['message']}." : '';

        if (!empty($message) || !empty($errors)) {
            throw new TerminusException('{service} error: {message} {errors}', ['service' => $service, 'message' => $message, 'errors' => implode("\n", $errors)]);
        }

        return $data;
    }

    protected function setCircleEnvironmentVars($circle_url, $token, $env)
    {
        foreach ($env as $key => $value) {
            $data = ['name' => $key, 'value' => $value];
            $this->curlCircleCI($data, "$circle_url/envvar", $token);
        }
    }

    protected function circleFollow($circle_url, $token)
    {
        $this->curlCircleCI([], "$circle_url/follow", $token);
    }

    protected function addPublicKeyToPantheonUser($publicKey)
    {
        $this->session()->getUser()->getSSHKeys()->addKey($publicKey);
    }

    protected function addPrivateKeyToCircleProject($circle_url, $token, $privateKey)
    {
        $privateKeyContents = file_get_contents($privateKey);
        $data = [
            'hostname' => 'drush.in',
            'private_key' => $privateKeyContents,
        ];
        $this->curlCircleCI($data, "$circle_url/ssh-key", $token);
    }

    protected function curlCircleCI($data, $url, $auth)
    {
        $this->log()->notice('Call CircleCI API: {uri}', ['uri' => $url]);
        $ch = $this->createBasicAuthenticationCurlChannel($url, $auth);
        $this->setCurlChannelPostData($ch, $data, true);
        return $this->execCurlRequest($ch, 'CircleCI');
    }

    protected function createGitHubCurlChannel($uri, $auth = '')
    {
        $url = "https://api.github.com/$uri";
        return $this->createAuthorizationHeaderCurlChannel($url, $auth);
    }

    protected function createGitHubPostChannel($uri, $postData = [], $auth = '')
    {
        $ch = $this->createGitHubCurlChannel($uri, $auth);
        $this->setCurlChannelPostData($ch, $postData);

        return $ch;
    }

    protected function createGitHubDeleteChannel($uri, $auth = '')
    {
        $ch = $this->createGitHubCurlChannel($uri, $auth);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');

        return $ch;
    }

    public function curlGitHub($uri, $postData = [], $auth = '')
    {
        $this->log()->notice('Call GitHub API: {uri}', ['uri' => $uri]);
        $ch = $this->createGitHubPostChannel($uri, $postData, $auth);
        return $this->execCurlRequest($ch, 'GitHub');
    }

    /**
     * Delete all of the build environments matching the pattern for transient
     * CI builds, i.e., all multidevs whose name begins with "ci-".
     *
     * @command build-env:delete:ci
     * @aliases build:env:delete:ci
     *
     * @param string $site_id Site name
     * @option keep Number of environments to keep
     * @option dry-run Only print what would be deleted; do not delete anything.
     */
    public function deleteBuildEnvCi(
        $site_id,
        $options = [
            'keep' => 0,
            'dry-run' => false,
        ])
    {
        // There should never be a PR that begins with the CI delete pattern,
        // but if there is, we will check for it and exclude that multidev
        // from consideration.
        $options['preserve-prs'] = true;

        // We always want to clean up the remote branch.
        $options['delete-branch'] = true;

        $options += [
            'keep' => 0,
            'preserve-if-branch' => false,
        ];

        return $this->deleteBuildEnv($site_id, self::TRANSIENT_CI_DELETE_PATTERN, $options);
    }

    /**
     * Delete all of the build environments matching the pattern for pull
     * request branches, i.e., all multidevs whose name begins with "pr-".
     *
     * @command build-env:delete:pr
     * @aliases build:env:delete:pr
     *
     * @param string $site_id Site name
     * @option dry-run Only print what would be deleted; do not delete anything.
     */
    public function deleteBuildEnvPR(
        $site_id,
        $options = [
            'dry-run' => false,
        ])
    {
        // Preserve any pull request that still has a corresponding branch in GitHub.
        $options['preserve-prs'] = true;

        // We always want to clean up the remote branch.
        $options['delete-branch'] = true;

        $options += [
            'keep' => 0,
            'preserve-if-branch' => false,
        ];

        return $this->deleteBuildEnv($site_id, self::PR_BRANCH_DELETE_PATTERN, $options);
    }

    // TODO: At the moment, this takes multidev environment names,
    // e.g.:
    //   pr-dc-worka
    // And compares them against a list of branches, e.g.:
    //   dc-workaround
    //   lightning-fist-2
    //   composer-merge-pantheon
    // In its current form, the 'pr-' is stripped from the beginning of
    // the environment name, and then a 'begins-with' test is done. This
    // is not perfect, but if it goes wrong, the result will be that a
    // multidev environment that should have been eligible for deletion will
    // not be deleted.
    //
    // This could be made better if we could fetch the build-metadata.json
    // file from the repository root of each multidev environment, which would
    // give us the correct branch name for every environment. We could do
    // this without too much trouble via rsync; this might be a little slow, though.
    protected function preserveEnvsWithGitHubBranches($oldestEnvironments, $multidev_delete_pattern)
    {
        $remoteBranch = 'origin';

        // Update the local repository -- prune / add remote branches.
        // We could use `git remote prune origin` to only prune remote branches.
        $this->passthru('git remote update --prune origin');

        // List all of the remote branches
        $outputLines = $this->exec('git branch -ar');

        // Remove branch lines that do not begin with 'origin/'
        $outputLines = array_filter(
            $outputLines,
            function ($item) use ($remoteBranch) {
                return preg_match("%^ *$remoteBranch/%", $item);
            }
        );

        // Strip the 'origin/' from the beginning of each branch line
        $outputLines = array_map(
            function ($item) use ($remoteBranch) {
                return preg_replace("%^ *$remoteBranch/%", '', $item);
            },
            $outputLines
        );

        return $this->filterBranches($oldestEnvironments, $outputLines, $multidev_delete_pattern);
    }

    protected function filterBranches($oldestEnvironments, $branchList, $multidev_delete_pattern)
    {
        // Filter environments that have matching remote branches in origin
        return array_filter(
            $oldestEnvironments,
            function ($item) use ($branchList, $multidev_delete_pattern) {
                $match = $item;
                // If the name is less than the maximum length, then require
                // an exact match; otherwise, do a 'starts with' test.
                if (strlen($item) < 11) {
                    $match .= '$';
                }
                // Strip the multidev delete pattern from the beginning of
                // the match. The multidev env name was composed by prepending
                // the delete pattern to the branch name, so this recovers
                // the branch name.
                $match = preg_replace("%$multidev_delete_pattern%", '', $match);
                // Constrain match to only match from the beginning
                $match = "^$match";

                // Find items in $branchList that match $match.
                $matches = preg_grep ("%$match%i", $branchList);
                return empty($matches);
            }
        );
    }

    protected function deleteEnv($env, $deleteBranch = false)
    {
        $workflow = $env->delete(['delete_branch' => $deleteBranch,]);
        $workflow->wait();
        if ($workflow->isSuccessful()) {
            $this->log()->notice('Deleted the multidev environment {env}.', ['env' => $env->id,]);
        } else {
            throw new TerminusException($workflow->getMessage());
        }
    }

    /**
     * Displays a list of the site's ci build environments, sorted with oldest first.
     *
     * @command build-env:list
     * @aliases build:env:list
     * @authorize
     *
     * @field-labels
     *     id: ID
     *     created: Created
     *     domain: Domain
     *     connection_mode: Connection Mode
     *     locked: Locked
     *     initialized: Initialized
     * @return RowsOfFields
     *
     * @param string $site_id Site name
     * @param string $multidev_delete_pattern Pattern identifying ci build environments
     * @usage env:list <site>
     *    Displays a list of <site>'s environments.
     */
    public function listOldest($site_id, $multidev_delete_pattern = self::DEFAULT_DELETE_PATTERN) {
        $siteList = $this->oldestEnvironments($site_id, $multidev_delete_pattern);

        return new RowsOfFields($siteList);
    }

    /**
     * Return a list of multidev environments matching the provided
     * pattern, sorted with oldest first.
     *
     * @param string $site_id Site to check.
     * @param string $multidev_delete_pattern Regex of environments to select.
     */
    protected function oldestEnvironments($site_id, $multidev_delete_pattern)
    {
        // Get a list of all of the sites
        $env_list = $this->getSite($site_id)->getEnvironments()->serialize();

        // Filter out the environments that do not match the multidev delete pattern
        $env_list = array_filter(
            $env_list,
            function ($item) use ($multidev_delete_pattern) {
                return preg_match("%$multidev_delete_pattern%", $item['id']);
            }
        );

        // Sort the environments by creation date, with oldest first
        uasort(
            $env_list,
            function ($a, $b) {
                if ($a['created'] == $b['created']) {
                    return 0;
                }
                return ($a['created'] < $b['created']) ? -1 : 1;
            }
        );

        return $env_list;
    }

    /**
     * Create a new multidev environment
     *
     * @param string $site_env Source site and environment.
     * @param string $multidev Name of environment to create.
     */
    public function create($site_env, $multidev)
    {
        list($site, $env) = $this->getSiteEnv($site_env, 'dev');
        $this->log()->notice("Creating multidev {env} for site {site}", ['site' => $site->getName(), 'env' => $multidev]);
        $workflow = $site->getEnvironments()->create($multidev, $env);
        while (!$workflow->checkProgress()) {
            // TODO: Add workflow progress output
        }
        $this->log()->notice($workflow->getMessage());
    }

    /**
     * Set the connection mode to 'sftp' or 'git' mode, and wait for
     * it to complete.
     *
     * @param Pantheon\Terminus\Models\Environment $env
     * @param string $mode
     */
    public function connectionSet($env, $mode)
    {
        $workflow = $env->changeConnectionMode($mode);
        if (is_string($workflow)) {
            $this->log()->notice($workflow);
        } else {
            while (!$workflow->checkProgress()) {
                // TODO: Add workflow progress output
            }
            $this->log()->notice($workflow->getMessage());
        }
    }

    /**
     * Wait for a workflow to complete.
     *
     * @param int $startTime Ignore any workflows that started before the start time.
     * @param string $workflow The workflow message to wait for.
     */
    protected function waitForCodeSync($startTime, $site, $env_name)
    {
        $this->waitForWorkflow($startTime, $site, $env_name);
    }

    /**
     * Wait for a workflow to complete. Usually this will be used to wait
     * for code commits, since Terminus will already wait for workflows
     * that it starts through the API.
     *
     * @command workflow:wait
     * @aliases build:workflow:wait
     * @param $site_env_id The pantheon site to wait for.
     * @param $description The workflow description to wait for. Optional; default is code sync.
     * @option start Ignore any workflows started prior to the start time (epoch)
     * @option max Maximum time in seconds to wait
     */
    public function workflowWait(
        $site_env_id,
        $description = '',
        $options = [
          'start' => 0,
          'max' => 60,
        ])
    {
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $env_name = $env->getName();

        $startTime = $options['start'];
        if (!$startTime) {
            $startTime = time() - 60;
        }
        $this->waitForWorkflow($startTime, $site, $env_name, $description, $options['max']);
    }

    protected function waitForWorkflow($startTime, $site, $env_name, $expectedWorkflowDescription = '', $maxWaitInSeconds = 60)
    {
        if (empty($expectedWorkflowDescription)) {
            $expectedWorkflowDescription = "Sync code on \"$env_name\"";
        }

        $startWaiting = time();
        while(time() - $startWaiting < $maxWaitInSeconds) {
            $workflow = $this->getLatestWorkflow($site);
            $workflowCreationTime = $workflow->get('created_at');
            $workflowDescription = $workflow->get('description');

            if (($workflowCreationTime > $startTime) && ($expectedWorkflowDescription == $workflowDescription)) {
                $this->log()->notice("Workflow '{current}' {status}.", ['current' => $workflowDescription, 'status' => $workflow->getStatus(), ]);
                if ($workflow->isSuccessful()) {
                    return;
                }
            }
            else {
                $this->log()->notice("Current workflow is '{current}'; waiting for '{expected}'", ['current' => $workflowDescription, 'expected' => $expectedWorkflowDescription]);
            }
            // Wait a bit, then spin some more
            sleep(5);
        }
    }

    /**
     * Fetch the info about the currently-executing (or most recently completed)
     * workflow operation.
     */
    protected function getLatestWorkflow($site)
    {
        $workflows = $site->getWorkflows()->fetch(['paged' => false,])->all();
        $workflow = array_shift($workflows);
        $workflow->fetchWithLogs();
        return $workflow;
    }

    /**
     * Return the metadata for this build.
     *
     * @return string[]
     */
    public function getBuildMetadata($repositoryDir)
    {
        return [
          'url'         => exec("git -C $repositoryDir config --get remote.origin.url"),
          'ref'         => exec("git -C $repositoryDir rev-parse --abbrev-ref HEAD"),
          'sha'         => $this->getHeadCommit($repositoryDir),
          'comment'     => exec("git -C $repositoryDir log --pretty=format:%s -1"),
          'commit-date' => exec("git -C $repositoryDir show -s --format=%ci HEAD"),
          'build-date'  => date("Y-m-d H:i:s O"),
        ];
    }

    /**
     * Write the build metadata into the build results prior to committing them.
     *
     * @param string[] $metadata
     */
    public function recordBuildMetadata($metadata, $repositoryDir)
    {
        $buildMetadataFile = "$repositoryDir/build-metadata.json";
        $metadataContents = json_encode($metadata, JSON_UNESCAPED_SLASHES);
        $this->log()->notice('Set {file} to {metadata}.', ['metadata' => $metadataContents, 'file' => basename($buildMetadataFile)]);

        file_put_contents($buildMetadataFile, $metadataContents);
    }

    /**
     * Iterate through the different environments, and keep fetching their
     * metadata until we find one that has a 'url' component.
     *
     * @param string $site_id The site to operate on
     * @param stirng[] $oldestEnvironments List of environments
     * @return string
     */
    protected function retrieveRemoteUrlFromBuildMetadata($site_id, $oldestEnvironments)
    {
        foreach ($oldestEnvironments as $env) {
            try {
                $metadata = $this->retrieveBuildMetadata("{$site_id}.{$env}");
                if (isset($metadata['url'])) {
                    return $metadata['url'];
                }
            }
            catch(\Exception $e) {
            }
        }
        return '';
    }

    /**
     * Get the build metadata from a remote site.
     *
     * @param string $site_env_id
     * @return string[]
     */
    public function retrieveBuildMetadata($site_env_id)
    {
        $src = ':code/build-metadata.json';
        $dest = '/tmp/build-metadata.json';

        $status = $this->rsync($site_env_id, $src, $dest);
        if ($status != 0) {
            return [];
        }

        $metadataContents = file_get_contents($dest);
        $metadata = json_decode($metadataContents, true);

        unlink($dest);

        return $metadata;
    }

    /**
     * Add or refresh the 'pantheon' remote
     */
    public function addPantheonRemote($env, $repositoryDir)
    {
        // Refresh the remote is already there (e.g. due to CI service caching), just in
        // case. If something changes, this info is NOT removed by "rebuild without cache".
        if ($this->hasPantheonRemote($repositoryDir)) {
            $this->passthru("git -C $repositoryDir remote remove pantheon");
        }
        $connectionInfo = $env->connectionInfo();
        $gitUrl = $connectionInfo['git_url'];
        $this->passthru("git -C $repositoryDir remote add pantheon $gitUrl");
    }

    /**
     * Check to see if there is a remote named 'pantheon'
     */
    protected function hasPantheonRemote($repositoryDir)
    {
        exec("git -C $repositoryDir remote show", $output);
        return array_search('pantheon', $output) !== false;
    }

    /**
     * Substitute replacements in a string. Replacements should be formatted
     * as {key} for raw value, or [[key]] for shell-escaped values.
     *
     * @param string $message
     * @param string[] $context
     * @return string[]
     */
    private function interpolate($message, array $context)
    {
        // build a replacement array with braces around the context keys
        $replace = array();
        foreach ($context as $key => $val) {
            if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                $replace[sprintf('{%s}', $key)] = $val;
                $replace[sprintf('[[%s]]', $key)] = ProcessUtils::escapeArgument($val);
            }
        }

        // interpolate replacement values into the message and return
        return strtr($message, $replace);
    }

    /**
     * Call rsync to or from the specified site.
     *
     * @param string $site_env_id Remote site
     * @param string $src Source path to copy from. Start with ":" for remote.
     * @param string $dest Destination path to copy to. Start with ":" for remote.
     */
    protected function rsync($site_env_id, $src, $dest)
    {
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $env_id = $env->getName();

        $siteInfo = $site->serialize();
        $site_id = $siteInfo['id'];

        $siteAddress = "$env_id.$site_id@appserver.$env_id.$site_id.drush.in:";

        $src = preg_replace('/^:/', $siteAddress, $src);
        $dest = preg_replace('/^:/', $siteAddress, $dest);

        $this->log()->notice('Rsync {src} => {dest}', ['src' => $src, 'dest' => $dest]);
        passthru("rsync -rlIvz --ipv4 --exclude=.git -e 'ssh -p 2222' $src $dest >/dev/null 2>&1", $status);

        return $status;
    }

    /**
     * Return 'true' if the specified file was changed in the provided commit.
     */
    protected function commitChangesFile($commit, $file)
    {
        // If there are any errors, we will presume that the file in
        // question does not exist in the repository and treat that as
        // "file did not change" (in other words, ignore errors).
        exec("git show --name-only $commit -- $file", $outputLines, $result);

        return ($result == 0) && !empty($outputLines);
    }

    /**
     * Call passthru; throw an exception on failure.
     *
     * @param string $command
     */
    protected function passthru($command, $loggedCommand = '')
    {
        $result = 0;
        $loggedCommand = empty($loggedCommand) ? $command : $loggedCommand;
        // TODO: How noisy do we want to be?
        $this->log()->notice("Running {cmd}", ['cmd' => $loggedCommand]);
        passthru($command, $result);

        if ($result != 0) {
            throw new TerminusException('Command `{command}` failed with exit code {status}', ['command' => $loggedCommand, 'status' => $result]);
        }
    }

    function passthruRedacted($command, $secret)
    {
        $loggedCommand = str_replace($secret, 'REDACTED', $command);
        $command .= " | sed -e 's/$secret/REDACTED/g'";

        return $this->passthru($command, $loggedCommand);
    }

    /**
     * Call exec; throw an exception on failure.
     *
     * @param string $command
     * @return string[]
     */
    protected function exec($command)
    {
        $result = 0;
        $this->log()->notice("Running {cmd}", ['cmd' => $command]);
        exec($command, $outputLines, $result);

        if ($result != 0) {
            throw new TerminusException('Command `{command}` failed with exit code {status}', ['command' => $command, 'status' => $result]);
        }
        return $outputLines;
    }

    // Create a temporary directory
    public function tempdir($prefix='php', $dir=FALSE)
    {
        $tempfile=tempnam($dir ? $dir : sys_get_temp_dir(), $prefix ? $prefix : '');
        if (file_exists($tempfile)) {
            unlink($tempfile);
        }
        mkdir($tempfile);
        chmod($tempfile, 0700);
        if (is_dir($tempfile)) {
            $this->tmpDirs[] = $tempfile;
            return $tempfile;
        }
    }

    // Delete our work directory on exit.
    public function cleanup()
    {
        if (empty($this->tmpDirs)) {
            return;
        }

        $fs = new Filesystem();
        $fs->remove($this->tmpDirs);
    }
}
