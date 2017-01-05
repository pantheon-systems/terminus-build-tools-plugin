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
use Pantheon\Terminus\Exceptions\TerminusException;
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
     * Create the specified multidev environment on the given Pantheon
     * site from the build assets at the current working directory.
     *
     * @command build-env:create
     * @param string $site_env_id The site and env of the SOURCE
     * @param string $multidev The name of the env to CREATE
     * @option label What to name the environment in commit comments
     */
    public function createBuildEnv($site_env_id, $multidev, $options = ['label' => ''])
    {
        // c.f. create-pantheon-multidev script
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $env_id = $env->getName();
        $env_label = $multidev;
        if (!empty($options['label'])) {
            $env_label = $options['label'];
        }

        // Add a remote named 'pantheon' to point at the Pantheon site's git repository.
        // Skip this step if the remote is already there (e.g. due to CI service caching).
        if (!$this->hasPantheonRemote()) {
            $connectionInfo = $env->connectionInfo();
            $gitUrl = $connectionInfo['git_url'];
            $this->passthru("echo git remote add pantheon $gitUrl");
        }
        $this->passthru('git fetch pantheon');

        // If we are testing against the dev environment, then simply force-push
        // our build assets to the master branch via rsync and exit. Note that
        // the modified files remain uncommitted unless build-env:merge is called.
        if ($multidev == $env_id) {
          $this->connectionSet($env, 'sftp');

          $siteInfo = $site->serialize();
          $site_id = $siteInfo['id'];
          $this->passthru("rsync -rlIvz --ipv4 --exclude=.git -e 'ssh -p 2222' ./ $env_id.$site_id@appserver.$env_id.$site_id.drush.in:code/");
          return;
        }

        // Create a new branch and commit the results from anything that may have changed
        $this->passthru("git checkout -B $multidev");
        $this->passthru('git add -A .');
        $this->passthru("git commit -q -m 'Build assets for $env_label.'");

        // Push the branch to Pantheon, and create a new environment for it
        $this->passthru("git push -q pantheon $multidev");

        // Create a new environment for this test.
        $this->create($site_env_id, $multidev);

        // Clear the environments, so that they will be re-fetched.
        // Otherwise, the new environment will not be found.
        $site->$environments = null;

        // Set the target environment to sftp mode
        $target_env = $site->getEnvironments()->get($multidev);
        $this->connectionSet($target_env, 'sftp');
    }

    /**
     * @command build-env:merge
     * @param string $site_env_id The site and env to merge and delete
     * @option label What to name the environment in commit comments
     */
    public function mergeBuildEnv($site_env_id, $options = ['label' => ''])
    {
        // c.f. merge-pantheon-multidev script
        list(, $env) = $this->getSiteEnv($site_env_id);
        $env_id = $env->getName();
        $env_label = $env;
        if (!empty($options['label'])) {
            $env_label = $options['label'];
        }

        // If we are building against the 'dev' environment, then simply
        // commit the changes once the PR is merged.
        if ($env_id == 'dev') {
            $env->commitChanges("Build assets for $env_label.");
            return;
        }

        // When using build-env:merge, we expect that the dev environment
        // should stay in git mode. We will switch it to git mode now to be sure.
        $this->connectionSet($env, 'git');

        // Replace the entire contents of the master branch with the branch we just tested.
        $this->passthru('git checkout master');
        $this->passthru("git merge -q -m 'Merge build assets from test $env_label.' -X theirs $env_id");

        // Push our changes back to the dev environment, replacing whatever was there before.
        $this->passthru('git push --force -q pantheon master');

        // Once the build environment is merged, we do not need it any more
        $this->deleteEnv($env, true);
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
            $this->deleteEnv($env, $options['delete-branch']);
        }
    }

    protected function deleteEnv($env, $deleteBranch = false)
    {
        $workflow = $env->delete(['delete_branch' => $deleteBranch,]);
        $workflow->wait();
        if ($workflow->isSuccessful()) {
            $this->log()->notice('Deleted the multidev environment {env}.', ['env' => $env->id,]);
        } else {
            throw new TerminusException($workflow->getMessage());
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

    // TODO: Use Multidev\CreateCommand in Terminus?
    public function create($site_env, $multidev)
    {
        list($site, $env) = $this->getSiteEnv($site_env, 'dev');
        $this->log()->notice("Create multidev '{env}' for site {site}", ['site' => $site->getName(), 'env' => $env->getName()]);
        $workflow = $site->getEnvironments()->create($multidev, $env);
        while (!$workflow->checkProgress()) {
            // TODO: Add workflow progress output
        }
        $this->log()->notice($workflow->getMessage());
    }

    public function connectionSet($env, $mode)
    {
        $workflow = $env->changeConnectionMode($mode);
        if (is_string($workflow)) {
            $this->log()->notice($workflow);
        } else {
            while (!$workflow->checkProgress()) {
                // TODO: Add workflow progress output
            }
            $this->log()->notice($workflow->getMessage());
        }
    }

    protected function hasPantheonRemote()
    {
        exec('git remote show', $output);
        return array_search('pantheon', $output) !== false;
    }

    protected function passthru($command)
    {
        $result = 0;
        passthru($command, $result);

        if ($result != 0) {
            throw new TerminusException('Command `{command}` failed with exit code {status}', ['command' => $command, 'status' => $result]);
        }
    }
}
