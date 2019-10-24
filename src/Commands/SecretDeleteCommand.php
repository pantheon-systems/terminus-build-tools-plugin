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
 * Secret Delete Command
 */
class SecretDeleteCommand extends BuildToolsBase
{

    /**
     * Remove a secret set via build:secret:set command.
     *
     * @command build:secrets:delete
     *
     * @param string $site_env_id Name of the environment to set the secret in.
     * @param string $key Item to set (or empty to delete everything)
     * @option file Name of file to store secrets in
     */
    public function delete(
        $site_env_id,
        $key,
        $options = [
            'file' => 'tokens.json',
        ])
    {
        $this->deleteSecrets($site_env_id, $key, $options['file']);
        $this->log()->notice("Deleted secret.");
    }
}
