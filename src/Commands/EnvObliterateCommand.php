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
 * Env Obliterate Command
 */
class EnvObliterateCommand extends BuildToolsBase
{
    /**
     * Destroy a Pantheon site that was created by the build:project:create command.
     *
     * @command build:env:obliterate
     * @aliases build-env:obliterate
     */
    public function obliterate($site_name)
    {
        $site = $this->getSite($site_name);

        // Fetch the build metadata from the specified site name and
        // look up the URL to the repository stored therein.
        $url = $this->getUrlFromBuildMetadata("{$site_name}.dev");

        // Create a git repository service provider appropriate to the URL
        $gitProvider = $this->inferGitProviderFromUrl($url);

        // Ensure that all of our providers are given the credentials they need.
        // Providers are not usable until this is done.
        $this->providerManager()->validateCredentials();

        // Do nothing without confirmation
        if (!$this->confirm('Are you sure you want to delete {site} AND its corresponding GitHub repository {url} and CircleCI configuration?', ['site' => $site->getName(), 'url' => $url])) {
            return;
        }

        $this->log()->notice('About to delete {site} and its corresponding remote repository {url} and CI configuration.', ['site' => $site->getName(), 'url' => $url]);

        // CircleCI configuration is automatically deleted when the GitHub
        // repository is deleted. Do we need to clean up for other CI providers?

        // Use the GitHub API to delete the GitHub project.
        $project = $this->projectFromRemoteUrl($url);

        // Delete the remote git repository.
        $gitProvider->deleteRepository($project);
        $this->log()->notice('Deleted {project} from {provider}', ['project' => $project, 'provider' => $GIT_PROVIDER]);

        // Use the Terminus API to delete the Pantheon site.
        $site->delete();
        $this->log()->notice('Deleted {site} from Pantheon', ['site' => $site_name,]);
    }
}
