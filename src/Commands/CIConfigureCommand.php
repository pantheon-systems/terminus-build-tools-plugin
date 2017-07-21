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
 * CI Configure Command
 */
class CIConfigureCommand extends BuildToolsBase
{

    /**
     * Configure CI Tests for a Pantheon site created from the specified
     * GitHub repository
     *
     * @authorize
     *
     * @command build:ci:configure
     * @aliases build-env:ci:configure
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
        // TODO: check to see if we need to downgrade to only use dev environment

        // Add the github token if available
        // TODO: How do we determine which Repository provider to use here?
        $github_token = getenv('GITHUB_TOKEN');
        if ($github_token) {
            $repositoryState['GITHUB_TOKEN'] = $github_token;
            $ci_env->storeState('repository', $repositoryState);
        }

        $this->configureCircle($target_project, $circle_token, $circle_env);
    }
}
