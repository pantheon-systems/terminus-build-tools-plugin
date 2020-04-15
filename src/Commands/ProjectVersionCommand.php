<?php
/**
 * Terminus Plugin that contain a collection of commands useful during
 * the build step on a [Pantheon](https://www.pantheon.io) site that uses
 * a Git PR workflow.
 *
 * See README.md for usage information.
 */

namespace Pantheon\TerminusBuildTools\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;

/**
 * Project Version Command
 */
class ProjectVersionCommand extends BuildToolsBase
{
    use \Pantheon\TerminusBuildTools\Task\Ssh\Tasks;
    use \Pantheon\TerminusBuildTools\Task\CI\Tasks;

    /**
     * Show build info attached to a site created by the
     * build:project:create command.
     *
     * @command build:project:version
     */
    public function version()
    {
        $info = $this->autodetectApplication(getcwd());

        if ($info) {
            $data = [
              'application' => $info['application'],
              'version' => $info['version'],
              'major_version' => $info['major_version'],
              'framework' => $info['framework'],
            ];

            return new RowsOfFields([$data]);
        } else {
            $this->log()->notice("Unable to determine version.");
        }

    }
}
