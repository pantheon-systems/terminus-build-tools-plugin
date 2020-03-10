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

/**
 * Secret List Command
 */
class SecretListCommand extends BuildToolsBase
{

    /**
     * Show all secret values.
     *
     * @command build:secrets:list
     *
     * @param string $site_env_id Name of the environment to set the secret in.
     * @option file Name of file to store secrets in
     */
    public function delete(
        $site_env_id,
        $options = [
            'file' => 'tokens.json',
        ])
    {
        $secretValues = $this->downloadSecrets($site_env_id, $options['file']);
        return new PropertyList($secretValues);
    }
}
