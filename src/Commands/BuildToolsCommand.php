<?php
/**
 * This command will manage secrets on a Pantheon site.
 *
 * See README.md for usage information.
 */

namespace Pantheon\TerminusBuildTools\Commands;

use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Manage secrets on a Pantheon instance
 */
class BuildToolsCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;

    const DEFAULT_DELETE_PATTERN = '^ci-';

    /**
     * Object constructor
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @command build-env:create
     */
    public function createBuildEnv()
    {
        // c.f. create-pantheon-multidev script
    }

    /**
     * @command build-env:merge
     */
    public function mergeBuildEnv($site_env_id, $options = ['label' => ''])
    {
        // c.f. merge-pantheon-multidev script
    }

    /**
     * Delete all of the build environments matching the provided pattern,
     * optionally keeping a few of the most recently-created.
     *
     * @command build-env:delete
     *
     * @param string $site_id Site name
     * @param string $multidev_delete_pattern Pattern used for build environments
     * @option keep Number of environments to keep
     * @option delete-branch Delete the git branch in addition to the multidev environment.
     */
    public function deleteBuildEnv($site_id, $multidev_delete_pattern = self::DEFAULT_DELETE_PATTERN, $options  = ['keep' => 0, 'delete-branch' => false])
    {
        // Look up the oldest environments
        $oldestEnvironments = $this->oldestEnvironments($site_id, $multidev_delete_pattern);

        // Stop if nothing matched
        if (empty($oldestEnvironments)) {
            $this->log()->notice('No environments matched the provided pattern "{pattern}".', ['pattern' => $multidev_delete_pattern]);
            return;
        }

        // Reduce result list down to just the env id
        $oldestEnvironments = array_map(
            function ($item) {
                return $item['id'];
            },
            $oldestEnvironments
        );

        // Separate list into 'keep' and 'oldest' lists.
        $environmentsToKeep = [];
        if ($options['keep']) {
            $environmentsToKeep = array_slice($oldestEnvironments, count($oldestEnvironments) - $options['keep']);
            $oldestEnvironments = array_slice($oldestEnvironments, 0, count($oldestEnvironments) - $options['keep']);
        }

        // Make a display message of the environments to delete and keep
        $deleteList = implode(',', $oldestEnvironments);
        $keepList = implode(',', $environmentsToKeep);
        if (empty($keepList)) {
            $keepList = 'none of the build environments';
        }

        // Stop if there is nothing to delete.
        if (empty($oldestEnvironments)) {
            $this->log()->notice('Nothing to delete. Keeping {keepList}.', ['keepList' => $keepList,]);
            return;
        }

        if (!$this->confirm('Are you sure you want to delete {deleteList}, keeping {keepList}?', ['deleteList' => $deleteList, 'keepList' => $keepList])) {
            return;
        }

        // Delete each of the selected environments.
        foreach ($oldestEnvironments as $env_id) {
            $site_env_id = "{$site_id}.{$env_id}";

            list (, $env) = $this->getSiteEnv($site_env_id);
            $workflow = $env->delete(['delete_branch' => $options['delete-branch'],]);
            $workflow->wait();
            if ($workflow->isSuccessful()) {
                $this->log()->notice('Deleted the multidev environment {env}.', ['env' => $env->id,]);
            } else {
                throw new TerminusException($workflow->getMessage());
            }
        }
    }

    /**
     * Displays a list of the site's ci build environments, sorted with oldest first.
     *
     * @command build-env:list
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

    /**
     * Return a list of multidev environments matching the provided
     * pattern, sorted with oldest first.
     */
    protected function oldestEnvironments($site_id, $multidev_delete_pattern)
    {
        // Get a list of all of the sites
        $env_list = $this->getSite($site_id)->getEnvironments()->serialize();

        // Filter out the environments that do not match the multidev delete pattern
        $env_list = array_filter(
            $env_list,
            function ($item) use ($multidev_delete_pattern) {
                return preg_match("%$multidev_delete_pattern%", $item['id']);
            }
        );

        // Sort the environments by creation date, with oldest first
        uasort(
            $env_list,
            function ($a, $b) {
                if ($a['created'] == $b['created']) {
                    return 0;
                }
                return ($a['created'] < $b['created']) ? -1 : 1;
            }
        );

        return $env_list;
    }
}
