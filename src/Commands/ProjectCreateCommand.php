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
 * Project Create Command
 */
class ProjectCreateCommand extends BuildToolsBase
{
    /**
     * Validate requested site name before prompting for additional information.
     *
     * @hook init build:project:create
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
     *  - Creates a GitHub repository forked from the source project.
     *  - Creates a Pantheon site to run the tests on.
     *  - Sets up Circle CI to test the repository.
     * In order to use this command, it is also necessary to provide
     * a set of secrets that are used to create the necessary projects,
     * and that are subsequentially cached in Circle CI for use during
     * the test run. Currently, these secrets must be provided via
     * environment variables; this keeps them out of the command history
     * and other places they may be inadvertantly observed.
     *
     * export TERMINUS_TOKEN machine_token_from_pantheon_dashboard
     * export GITHUB_TOKEN github_personal_access_token
     * export CIRCLE_TOKEN circle_personal_api_token
     *
     * @authorize
     *
     * @command build:project:create
     * @alias build-env:create-project
     * @param string $source Packagist org/name of source template project to fork.
     * @param string $target Simple name of project to create.
     * @option org GitHub organization (defaults to authenticated user)
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
        list($target_project, $siteDir) = $this->createGitHub($source, $target, $github_org, $github_token, $stability);

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
}
