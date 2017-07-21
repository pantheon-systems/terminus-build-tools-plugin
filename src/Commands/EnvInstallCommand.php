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
 * Env Install Command
 */
class EnvInstallCommand extends BuildToolsBase
{

    /**
     * Install the apporpriate CMS on the newly-created Pantheon site.
     *
     * @command build:env:install
     * @aliases build-env:site-install
     */
    public function installSite(
        $site_env_id,
        $siteDir = '',
        $site_install_options = [
            'account-mail' => '',
            'account-name' => '',
            'account-pass' => '',
            'site-mail' => '',
            'site-name' => ''
        ])
    {
        if (empty($siteDir)) {
            $siteDir = getcwd();
        }
        $composer_json = $this->getComposerJson($siteDir);
        return $this->doInstallSite($site_env_id, $composer_json, $site_install_options);
    }
}
