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
 * Session Show Command
 */
class SessionShowCommand extends BuildToolsBase
{
    /**
     * Show all secret values.
     *
     * @authorize
     *
     * @command build:session:show
     * @aliases session:show
     */
    public function sessionShow()
    {
    	  $token = $this->recoverSessionMachineToken();
        if (!$token) {
            throw new \Exception("Could not determine session token.");
        }
    	  print $token . "\n";
    }
}
