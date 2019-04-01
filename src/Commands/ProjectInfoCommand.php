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
 * Project Repair Command
 */
class ProjectInfoCommand extends BuildToolsBase
{
    use \Pantheon\TerminusBuildTools\Task\Ssh\Tasks;
    use \Pantheon\TerminusBuildTools\Task\CI\Tasks;

    /**
     * Show build info attached to a site created by the
     * build:project:create command.
     *
     * @command build:project:info
     */
    public function info(
        $site_name,
        $options = [
            'ci' => '',
        ])
    {
        // Fetch the build metadata
        $buildMetadata = $this->retrieveBuildMetadata("{$site_name}.dev");
        $url = $this->getMetadataUrl($buildMetadata);

        $this->log()->notice('Build metadata: {metadata}', ['metadata' => var_export($buildMetadata, true)]);

        $target_project = $this->setProjectInfo($url, $options['ci']);

        // Ensure that all of our providers are given the credentials they need.
        // Providers are not usable until this is done.
        $this->providerManager()->validateCredentials();

        // Fetch the project name.
        $this->log()->notice('Found project {project}', ['project' => $target_project]);
    }
}
