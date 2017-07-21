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
}
