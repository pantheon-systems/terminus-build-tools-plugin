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
    use \Pantheon\TerminusBuildTools\Task\CI\Tasks;

    /**
     * Obsolete. Use build:project:repair instead.
     *
     * @authorize
     *
     * @command build:ci:configure
     * @aliases build-env:ci:configure
     * @param $site_name The pantheon site to test.
     */
    public function configureCI($site_name,
        $options = [
            'email' => '',
            'admin-password' => '',
            'admin-email' => '',
            'ci' => 'circle'
        ])
    {
        throw new TerminusException('The command build:ci:configure is obsolete. Please use build:project:repair instead.');
    }
}
