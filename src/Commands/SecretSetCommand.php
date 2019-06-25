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
 * Secret Set Command
 */
class SecretSetCommand extends BuildToolsBase
{

    /**
     * Set a secret value on Pantheon.
     *
     * @command build:secrets:set
     *
     * @param string $site_env_id Name of the environment to set the secret in.
     * @param string $key Item to set
     * @param string $value Value to set it to
     * @option file Name of file to store secrets in
     * @option clear Overwrite existing values
     * @option skip-if-empty Don't write anything unless '$value' is non-empty
     */
    public function set(
        $site_env_id,
        $key,
        $value,
        $options = [
            'file' => 'tokens.json',
            'clear' => false,
            'skip-if-empty' => false,
        ])
    {
        if ($options['skip-if-empty'] && empty($value))
        {
            return;
        }

        $this->writeSecrets($site_env_id, [$key => $value], $options['clear'], $options['file']);
        $this->log()->notice("Recorded secret.");
    }
}
