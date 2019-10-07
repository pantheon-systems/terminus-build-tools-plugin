<?php
/**
 * Terminus Plugin that contain a collection of commands useful during
 * the build step on a [Pantheon](https://www.pantheon.io) site that uses
 * a Git PR workflow.
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
use Pantheon\TerminusBuildTools\ServiceProviders\SiteProviders\SiteProvider;
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
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Project Convert Command
 */
class ProjectConvertCommand extends BuildToolsBase {

    /**
     * Converts a project to the Terminus BT CI workflow.
     *
     * @authorize
     *
     * @command build:project:convert
     * @param string $source The Git URL of the existing website.
     */
    public function convertProject($source) {
        $this->warnAboutOldPhp();

        // First things first, we need to figure out if we have a Pantheon site or a Git site.
        // ssh://codeserver.dev.4c378f81-a3d6-407b-a116-c4b1b0c4c4a9@codeserver.dev.4c378f81-a3d6-407b-a116-c4b1b0c4c4a9.drush.in:2222/~/repository.git
        if ($this->provider_manager->inferProvider($source, SiteProvider::class)) {
            
        }

    }

}