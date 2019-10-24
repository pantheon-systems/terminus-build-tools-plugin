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
 * Credential Clear Command
 *
 * Erase all cached credentials
 */
class CredentialsClearCommand extends BuildToolsBase
{
    use \Pantheon\TerminusBuildTools\Task\Ssh\Tasks;
    use \Pantheon\TerminusBuildTools\Task\CI\Tasks;

    /**
     * Delete cached credentials.
     *
     * @command build:credentials:clear
     * @aliases build:cc
     */
    public function credentialsClear(
        $options = [
            'ci' => '',
        ])
    {
        $this->providerManager()->credentialManager()->clearCache();
        $this->log()->notice('Credential cache cleared');
    }
}
