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
use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CIState;
use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;
use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\RepositoryEnvironment;
use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CircleCIProvider;
use Pantheon\TerminusBuildTools\Task\Ssh\PublicKeyReciever;

/**
 * Project Create Command
 *
 * TODO: We could create a SiteProvider that implements PublicKeyReciever
 * rather than using $this to server that purpose.
 */
class ProjectCreateCommand extends BuildToolsBase implements PublicKeyReciever
{
    use \Pantheon\TerminusBuildTools\Task\Ssh\Tasks;
    use \Pantheon\TerminusBuildTools\Task\CI\Tasks;

    protected $ci_provider;

    /**
     * Validate requested site name before prompting for additional information.
     *
     * @hook init build:project:create
     */
    public function validateSiteName(InputInterface $input, AnnotationData $annotationData)
    {
        $target_org = $input->getOption('org');
        $site_name = $input->getOption('pantheon-site');
        $source = $input->getArgument('source');
        $target = $input->getArgument('target');

        // TODO: select kind of CI provider to create from user options
        $this->ci_provider = new CircleCIProvider();
        $this->ci_provider->setLogger($this->log());

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
            list($target_org, $target) = explode('/', $target, 2);
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
        $input->setOption('org', $target_org);
        $input->setOption('pantheon-site', $site_name);
    }

    /**
     * Ensure that the user has provided credentials for GitHub and Circle CI,
     * and prompt for them if they have not.
     *
     * n.b. This hook is not called in --no-interaction mode.
     *
     * @hook interact build:project:create
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
                $this->log()->warning('GitHub authentication tokens should be 40-character strings containing only the letters a-f and digits (0-9). Please enter your token again.');
                $github_token = '';
            }
        }

        // Ask for a Circle token if one is not available.
        $circle_token = getenv('CIRCLE_TOKEN');
        while (!$this->ci_provider->hasToken()) {
            // TODO: Get info for prompt from provider
            $circle_token = $this->io()->askHidden("Please generate a Circle CI personal API token by visiting the page:\n\n    https://circleci.com/account/api\n\n For more information, see:\n\n    https://circleci.com/docs/api/v1-reference/#getting-started\n\n Then, enter it here:");
            $circle_token = trim($circle_token);
            $this->ci_provider->setToken($circle_token);

            // TODO: validate token via method in provider
            // Validate that the CircleCI token looks correct. If not, prompt again.
            if ((strlen($circle_token) < 40) || preg_match('#[^0-9a-fA-F]#', $circle_token)) {
                $this->log()->warning('Circle CI authentication tokens should be 40-character strings containing only the letters a-f and digits (0-9). Please enter your token again.');
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
     * @hook validate build:project:create
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
     * @command build:project:create
     * @alias build-env:create-project
     * @param string $source Packagist org/name of source template project to fork.
     * @param string $target Simple name of project to create.
     * @option org Organization for the new project (defaults to authenticated user)
     * @option team Pantheon team
     * @option pantheon-site Name of Pantheon site to create (defaults to 'target' argument)
     * @option email email address to place in ssh-key
     * @option stability Minimum allowed stability for template project.
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
        ])
    {
        $this->warnAboutOldPhp();
        $options = $this->validateOptionsAndSetDefaults($options);

        // Copy options into ordinary variables
        $target_org = $options['org'];
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

        // This target label is only used for the log messages below.
        $target_label = $target;
        if (!empty($target_org)) {
            $target_label = "$target_org/$target";
        }

        // Create a working directory
        $tmpsitedir = $this->tempdir('local-site');

        // Get the environment variables to be stored in the CI server.
        $ci_env = $this->getCIEnvironment($site_name, $options);

        // TEMPORARY: We'll fetch this from the repository provider later
        $repositoryAttributes = (new RepositoryEnvironment())
            ->setServiceName('github')
            ->setToken('GITHUB_TOKEN', $github_token);
        $ci_env->storeState('repository', $repositoryAttributes);

        // Pull down the source project
        $this->log()->notice('Create a local working copy of {src}', ['src' => $source]);
        $siteDir = $this->createFromSourceProject($source, $target, $tmpsitedir, $stability);

        $builder = $this->collectionBuilder();
        $builder

            // ->setStateValue('ci-env', $ci_env)

            // Create a GitHub repository
            ->progressMessage('Create GitHub project {target}', ['target' => $target_label])
            /*
            ->taskRepositoryCreate() // 'github' for now, becomes plugable via configuration in future
                ->target($target)
                ->owningOrganization($target_org)
                ->token($github_token)
                ->dir($siteDir)
            */
            ->addCode(
                function ($state) use ($ci_env, $target, $target_org, $github_token, $siteDir) {
                    $repositoryAttributes = $ci_env->getState('repository');

                    $target_project = $this->createGitHub($target, $siteDir, $target_org, $github_token);
                    $this->log()->notice('The target is {target}', ['target' => $target_project]);
                    $repositoryAttributes->setProjectId($target_project);
                })

            // TODO: rollback GitHub repository create

            // Create a Pantheon site
            ->progressMessage('Create Pantheon site {site}', ['site' => $site_name])
            ->addCode(
                function ($state) use ($site_name, $label, $team, $target, $siteDir) {
                    // Look up our upstream.
                    $upstream = $this->autodetectUpstream($siteDir);

                    $this->log()->notice('About to create Pantheon site {site} in {team} with upstream {upstream}', ['site' => $site_name, 'team' => $team, 'upstream' => $upstream]);

                    $site = $this->siteCreate($site_name, $label, $upstream, ['org' => $team]);

                    $siteInfo = $site->serialize();
                    $site_uuid = $siteInfo['id'];

                    $this->log()->notice('Created a new Pantheon site with UUID {uuid}', ['uuid' => $site_uuid]);

                    // Create a new README file to point to the Pantheon dashboard and dev site.
                    // Put in a placeholder for the CI badge to be inserted into later.
                    $ciPlaceholder = "![CI none](https://img.shields.io/badge/ci-none-orange.svg)";
                    $badgeTargetLabel = strtr($target, '-', '_');
                    $pantheonBadge = "[![Dashboard {$target}](https://img.shields.io/badge/dashboard-{$badgeTargetLabel}-yellow.svg)](https://dashboard.pantheon.io/sites/{$site_uuid}#dev/code)";
                    $siteBadge = "[![Dev Site {$target}](https://img.shields.io/badge/site-{$badgeTargetLabel}-blue.svg)](http://dev-{$target}.pantheonsite.io/)";
                    $readme = "# $target\n\n$ciPlaceholder\n$pantheonBadge\n$siteBadge";

                    file_put_contents("$siteDir/README.md", $readme);

                    // If this site cannot create multidev environments, then configure
                    // it to always run tests on the dev environment.
                    $state['has-multidev-capability'] = $this->siteHasMultidevCapability($site);
                })

            // TODO: rollback Pantheon site create

            // Set up CircleCI to test our project.
            ->taskCIConfigure()
                ->provider($this->ci_provider)
                ->environment($ci_env)
                ->deferTaskConfiguration('hasMultidevCapability', 'has-multidev-capability')
                ->dir($siteDir)

            // Create new repository and make the initial commit
            ->progressMessage('Make initial commit')
            ->addCode(
                function ($state) use ($siteDir) {
                    $this->initialCommit($siteDir);
                })

            // n.b. Existing algorithm also pushes to GitHub here, but this is not necessary

            // Push code to newly-created project.
            ->progressMessage('Push code to Pantheon site {site}', ['site' => $site_name])
            ->addCode(
                function ($state) use ($site_name, $siteDir) {
                    // Remember the initial commit sha
                    $initial_commit = $this->getHeadCommit($siteDir);

                    $this->pushCodeToPantheon("{$site_name}.dev", 'dev', $siteDir);
                    // Remove the commit added by pushCodeToPantheon; we don't need the build assets locally any longer.
                    $this->resetToCommit($siteDir, $initial_commit);
                })

            // Install our site and export configuration
            ->progressMessage('Install CMS on Pantheon site {site}', ['site' => $site_name])
            ->addCode(
                function ($state) use ($ci_env, $site_name, $siteDir) {
                    $siteAttributes = $ci_env->getState('site');
                    $composer_json = $this->getComposerJson($siteDir);

                    // Install the site.
                    $site_install_options = [
                        'account-mail' => $siteAttributes->adminEmail(),
                        'account-name' => 'admin',
                        'account-pass' => $siteAttributes->adminPassword(),
                        'site-mail' => $siteAttributes->adminEmail(),
                        'site-name' => $siteAttributes->testSiteName(),
                    ];
                    $this->doInstallSite("{$site_name}.dev", $composer_json, $site_install_options);

                    // Before any tests have been configured, export the
                    // configuration set up by the installer.
                    $this->exportInitialConfiguration("{$site_name}.dev", $siteDir, $composer_json, $site_install_options);
                })

            // Push the local working repository to the server
            ->progressMessage('Push code and configuration to {target}', ['target' => $target_label])
            /*
            ->taskRepositoryPush()
                ->target($this->target_project)
                ->token($github_token)
                ->dir($siteDir)
            */
            ->addCode(
                function ($state) use ($ci_env, $github_token, $siteDir) {
                    $repositoryAttributes = $ci_env->getState('repository');
                    $this->pushToGitHub($github_token, $repositoryAttributes->projectId(), $siteDir);
                })

            // Set up CircleCI to test our project.
            // TODO: Add repository provider also
            ->taskCreateKeys()
                ->environment($ci_env)
                ->provider($this->ci_provider)
                ->provider($this) // TODO: replace with site provider

            // Tell the CI server to start testing our project
            ->taskCIStartTesting()
                ->provider($this->ci_provider)
                ->environment($ci_env);

//         $this->log()->notice('Your new site repository is {github}', ['github' => "https://github.com/{$this->target_project}"]);

        // If we return the builder, Robo will run it. This also allows
        // command hooks to alter the task collection prior to execution.
        return $builder;
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

    // TODO: This could move to a SiteProvider class. Would need a
    // reference to $this->session().
    public function addPublicKey(CIState $ci_env, $publicKey)
    {
        $this->session()->getUser()->getSSHKeys()->addKey($publicKey);
    }
}
