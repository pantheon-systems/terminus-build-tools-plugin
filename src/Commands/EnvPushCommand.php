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
 * Env Push Command
 */
class EnvPushCommand extends BuildToolsBase
{
    /**
     * Push code to a specific Pantheon site and environment that already exists.
     *
     * @command build:env:push
     * @aliases build-env:push-code
     *
     * @param string $site_env_id Site and environment to push to. May be any dev or multidev environment.
     * @param string $repositoryDir Code to push. Defaults to cwd.
     */
    public function pushCode(
        $site_env_id,
        $repositoryDir = '',
        $options = [
          'label' => '',
          'message' => '',
        ])
    {
        return $this->pushCodeToPantheon($site_env_id, '', $repositoryDir, $options['label'], $options['message']);
    }
}
