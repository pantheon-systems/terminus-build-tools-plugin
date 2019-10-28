<?php
/**
 * Terminus Plugin that contain a collection of commands useful during
 * the build step on a [Pantheon](https://www.pantheon.io) site that uses
 * a GitHub PR workflow.
 *
 * See README.md for usage information.
 */

namespace Pantheon\TerminusBuildTools\Commands;

use Pantheon\Terminus\Exceptions\TerminusException;

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
