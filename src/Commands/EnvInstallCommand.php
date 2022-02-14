<?php
/**
 * Terminus Plugin that contain a collection of commands useful during
 * the build step on a [Pantheon](https://www.pantheon.io) site that uses
 * a GitHub PR workflow.
 *
 * See README.md for usage information.
 */

namespace Pantheon\TerminusBuildTools\Commands;

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
            'site-name' => '',
            'profile' => ''
        ])
    {
        if (empty($siteDir)) {
            $siteDir = getcwd();
        }
        $composer_json = $this->getComposerJson($siteDir);
        return $this->doInstallSite($site_env_id, $composer_json, $site_install_options);
    }
}
