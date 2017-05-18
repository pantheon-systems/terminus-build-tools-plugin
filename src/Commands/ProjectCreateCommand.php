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
