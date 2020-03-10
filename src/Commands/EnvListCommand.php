<?php
/**
 * Terminus Plugin that contain a collection of commands useful during
 * the build step on a [Pantheon](https://www.pantheon.io) site that uses
 * a GitHub PR workflow.
 *
 * See README.md for usage information.
 */

namespace Pantheon\TerminusBuildTools\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;

/**
 * Env List Command
 */
class EnvListCommand extends BuildToolsBase
{

    /**
     * Displays a list of the site's ci build environments, sorted with oldest first.
     *
     * @command build:env:list
     * @aliases build-env:list
     * @authorize
     *
     * @field-labels
     *     id: ID
     *     created: Created
     *     domain: Domain
     *     connection_mode: Connection Mode
     *     locked: Locked
     *     initialized: Initialized
     * @return RowsOfFields
     *
     * @param string $site_id Site name
     * @param string $multidev_delete_pattern Pattern identifying ci build environments
     * @usage env:list <site>
     *    Displays a list of <site>'s environments.
     */
    public function listOldest($site_id, $multidev_delete_pattern = self::DEFAULT_DELETE_PATTERN) {
        $siteList = $this->oldestEnvironments($site_id, $multidev_delete_pattern);

        return new RowsOfFields($siteList);
    }
}
