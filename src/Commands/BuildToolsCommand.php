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
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\ProcessUtils;

/**
 * Build Tool Commands
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
     * @option notify Command to exec to notify when a build environment is created
     */
    public function createBuildEnv(
        $site_env_id,
        $multidev,
        $options = [
            'label' => '',
            'notify' => '',
        ])
    {
        // c.f. create-pantheon-multidev script
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $env_id = $env->getName();
        $env_label = $multidev;
        if (!empty($options['label'])) {
            $env_label = $options['label'];
        }

        // Fetch the site id also
        $siteInfo = $site->serialize();
        $site_id = $siteInfo['id'];

        // Add a remote named 'pantheon' to point at the Pantheon site's git repository.
        // Skip this step if the remote is already there (e.g. due to CI service caching).
        if (!$this->hasPantheonRemote()) {
            $connectionInfo = $env->connectionInfo();
            $gitUrl = $connectionInfo['git_url'];
            $this->passthru("git remote add pantheon $gitUrl");
        }
        $this->passthru('git fetch pantheon');

        // Check to see if '$multidev' already exists on Pantheon.
        $environmentExists = $site->getEnvironments()->has($multidev);

        // If we are testing against the dev environment, then simply force-push
        // our build assets to the master branch via rsync and exit. Note that
        // the modified files remain uncommitted unless build-env:merge is called.
        //
        // We also use this same code path for any test run after the first to
        // any given multidev site. This will only ever happen for `pr-` builds,
        // as the `ci-` builds are created for every test run, and therefore will
        // never receive more commits after the first. In the case of PR builds,
        // subsequent builds will overwrite any test still in progress, with
        // unpredictable results. The changed files will be rsync'ed over the
        // previous commits; the new commits will NOT be pushed to the Pantheon
        // branch, as that would require switching from SFTP mode to Git mode,
        // doing the push, and then switching back to SFTP mode. This is slow,
        // and there are race conditions on both transitions. We therefore use
        // rsync to get the code to Pantheon, and let the changed files "pile up"
        // uncommitted. Eventually, the PR will be merged on GitHub, at which
        // point all of the right commits will be merged into the master branch.
        // That will result in one more test, this time with a 'ci-' build that
        // always starts with a fresh multidev and a force-push of the lean
        // repository commits, followed by a single commit with the build assets
        // for this test. The end result is that the dev environment will always
        // be an exact match of the lean repository, plus just one last commit
        // with only the most recent build artifacts.
        if ($environmentExists) {
            $this->connectionSet($env, 'sftp');
            $this->passthru("rsync -rlIvz --ipv4 --exclude=.git -e 'ssh -p 2222' ./ $env_id.$site_id@appserver.$env_id.$site_id.drush.in:code/");
            return;
        }

        // Record the metadata for this build
        $metadata = $this->getBuildMetadata();
        $this->recordBuildMetadata($metadata);

        // Create a new branch and commit the results from anything that may
        // have changed. We presume that the source repository is clean of
        // any unwanted files prior to the build step (e.g. after a clean
        // checkout in a CI environment.)
        $this->passthru("git checkout -B $multidev");
        $this->passthru('git add --force -A .');

        // Exclude any .git files added above from the set of files being
        // committed. Ideally, there will be none.
        $finder = new Finder();
        foreach (
          $finder
            ->directories()
            ->in(getcwd())
            ->ignoreDotFiles(false)
            ->ignoreVCS(false)
            ->depth('> 0')
            ->name('.git')
          as $dir) {
          $this->passthru('git reset HEAD ' . $dir->getRelativePathname());
        }

        // Now that everything is ready, commit the build artifacts.
        $this->passthru("git commit -q -m 'Build assets for $env_label.'");

        // Push the branch to Pantheon, and create a new environment for it
        $this->passthru("git push --force -q pantheon $multidev");

        // Create a new environment for this test.
        $this->create($site_env_id, $multidev);

        // Clear the environments, so that they will be re-fetched.
        // Otherwise, the new environment will not be found.
        $site->environments = null;

        // Set the target environment to sftp mode
        $target_env = $site->getEnvironments()->get($multidev);
        $this->connectionSet($target_env, 'sftp');

        // If '--notify' was passed, then exec the notify command
        if (!empty($options['notify'])) {
            $site_name = $site->getName();
            $project = preg_replace('#[^:/]*[:/]([^/:]*/[^.]*)\.git#', '\1', str_replace('https://', '', $metadata['url']));
            $metadata += [
                'project' => $project,
                'site-id' => $site_id,
                'site' => $site_name,
                'env' => $env_id,
                'label' => $env_label,
                'dashboard-url' => "https://dashboard.pantheon.io/sites/{$site_id}#{$env_id}",
                'site-url' => "https://{$env_id}-{$site_name}.pantheonsite.io/",
            ];

            $command = $this->interpolate($options['notify'], $metadata);

            // Run notification command. Ignore errors.
            passthru($command);
        }
    }

    /**
     * @command build-env:merge
     * @param string $site_env_id The site and env to merge and delete
     * @option label What to name the environment in commit comments
     */
    public function mergeBuildEnv($site_env_id, $options = ['label' => ''])
    {
        // c.f. merge-pantheon-multidev script
        list($site, $env) = $this->getSiteEnv($site_env_id);
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
        $dev_env = $site->getEnvironments()->get('dev');
        $this->connectionSet($dev_env, 'git');

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
     * optionally keeping a few of the most recently-created. Also, optionally
     * any environment that still has a remote branch on GitHub may be preserved.
     *
     * TODO: It would be good if we could use the GitHub API to test to see if
     * the remote branch has been merged, and treat those branches as if they
     * were deleted branches.  This should be possible per
     * https://developer.github.com/v3/pulls/#list-pull-requests.
     * See https://github.com/pantheon-systems/terminus-build-tools-plugin/issues/1
     *
     * @command build-env:delete
     *
     * @param string $site_id Site name
     * @param string $multidev_delete_pattern Pattern used for build environments
     * @option keep Number of environments to keep
     * @option preserve-prs Keep any environment that still has a remote branch that has not been deleted.
     * @option delete-branch Delete the git branch in addition to the multidev environment.
     * @option dry-run Only print what would be deleted; do not delete anything.
     */
    public function deleteBuildEnv(
        $site_id,
        $multidev_delete_pattern = self::DEFAULT_DELETE_PATTERN,
        $options = [
            'keep' => 0,
            'preserve-prs' => false,
            'delete-branch' => false,
            'dry-run' => false,
        ])
    {
        // Look up the oldest environments matching the delete pattern
        $oldestEnvironments = $this->oldestEnvironments($site_id, $multidev_delete_pattern);

        // Stop if nothing matched
        if (empty($oldestEnvironments)) {
            $this->log()->notice('No environments matched the provided pattern "{pattern}".', ['pattern' => $multidev_delete_pattern]);
            return;
        }

        // Reduce result list down to just the env id ('ci-123' et. al.)
        $oldestEnvironments = array_map(
            function ($item) {
                return $item['id'];
            },
            $oldestEnvironments
        );

        // Reduce result list down to just those that do NOT have remote
        // branches in GitHub
        $environmentsToKeep = [];
        if (!empty($options['preserve-prs'])) {
            $environmentsWithoutBranches = $this->preserveEnvsWithGitHubBranches($oldestEnvironments, $multidev_delete_pattern);
            $environmentsToKeep = array_diff($oldestEnvironments, $environmentsWithoutBranches);
            $oldestEnvironments = $environmentsWithoutBranches;
        }

        // Separate list into 'keep' and 'oldest' lists.
        if ($options['keep']) {
            $environmentsToKeep = array_merge(
                $environmentsToKeep,
                array_slice($oldestEnvironments, count($oldestEnvironments) - $options['keep'])
            );
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

        if ($options['dry-run']) {
            $this->log()->notice('Dry run: would delete {deleteList} and keep {keepList}', ['deleteList' => $deleteList, 'keepList' => $keepList]);
            return;
        }

        if (!$this->confirm('Are you sure you want to delete {deleteList} and keep {keepList}?', ['deleteList' => $deleteList, 'keepList' => $keepList])) {
            return;
        }

        // Delete each of the selected environments.
        foreach ($oldestEnvironments as $env_id) {
            $site_env_id = "{$site_id}.{$env_id}";

            list (, $env) = $this->getSiteEnv($site_env_id);
            $this->deleteEnv($env, $options['delete-branch']);
        }
    }

    protected function preserveEnvsWithGitHubBranches($oldestEnvironments, $multidev_delete_pattern)
    {
        $remoteBranch = 'origin';

        // Update the local repository -- prune / add remote branches.
        // We could use `git remote prune origin` to only prune remote branches.
        $this->passthru('git remote update --prune origin');

        // List all of the remote branches
        $outputLines = $this->exec('git branch -ar');

        // Remove branch lines that do not begin with 'origin/'
        $outputLines = array_filter(
            $outputLines,
            function ($item) use ($remoteBranch) {
                return preg_match("%^ *$remoteBranch/%", $item);
            }
        );

        // Strip the 'origin/' from the beginning of each branch line
        $outputLines = array_map(
            function ($item) use ($remoteBranch) {
                return preg_replace("%^ *$remoteBranch/%", '', $item);
            },
            $outputLines
        );

        // Filter environments that have matching remote branches in origin
        return array_filter(
            $oldestEnvironments,
            function ($item) use ($outputLines, $multidev_delete_pattern) {
                $match = $item;
                // If the name is less than the maximum length, then require
                // an exact match; otherwise, do a 'starts with' test.
                if (strlen($item) < 11) {
                    $match .= '$';
                }
                // Strip the multidev delete pattern from the beginning of
                // the match. The multidev env name was composed by prepending
                // the delete pattern to the branch name, so this recovers
                // the branch name.
                $match = preg_replace("%$multidev_delete_pattern%", '', $match);
                // Constrain match to only match from the beginning
                $match = "^$match";

                // Find items in $outputLines that match $match.
                $matches  = preg_grep ("%$match%i", $outputLines);
                return empty($matches);
            }
        );
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
        $this->log()->notice("Creating multidev {env} for site {site}", ['site' => $site->getName(), 'env' => $multidev]);
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

    public function getBuildMetadata()
    {
        return [
          'url'         => exec('git config --get remote.origin.url'),
          'ref'         => exec('git rev-parse --abbrev-ref HEAD'),
          'sha'         => exec('git rev-parse HEAD'),
          'comment'     => exec('git log --pretty=format:%s -1'),
          'commit-date' => exec('git show -s --format=%ci HEAD'),
          'build-date'  => date('Y-m-d H:i:s O'),
        ];
    }

    public function recordBuildMetadata($metadata)
    {
        $buildMetadataFile = 'build-metadata.json';
        $metadataContents = json_encode($metadata);
        $this->log()->notice('Wrote {metadata} to {file}. cwd is {cwd}', ['metadata' => $metadataContents, 'file' => $buildMetadataFile, 'cwd' => getcwd()]);

        file_put_contents($buildMetadataFile, $metadataContents);
    }

    protected function hasPantheonRemote()
    {
        exec('git remote show', $output);
        return array_search('pantheon', $output) !== false;
    }

    private function interpolate($message, array $context)
    {
        // build a replacement array with braces around the context keys
        $replace = array();
        foreach ($context as $key => $val) {
            if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                $replace[sprintf('{%s}', $key)] = $val;
                $replace[sprintf('[[%s]]', $key)] = ProcessUtils::escapeArgument($val);
            }
        }

        // interpolate replacement values into the message and return
        return strtr($message, $replace);
    }

    protected function passthru($command)
    {
        $result = 0;
        passthru($command, $result);

        if ($result != 0) {
            throw new TerminusException('Command `{command}` failed with exit code {status}', ['command' => $command, 'status' => $result]);
        }
    }

    protected function exec($command)
    {
        $result = 0;
        exec($command, $outputLines, $result);

        if ($result != 0) {
            throw new TerminusException('Command `{command}` failed with exit code {status}', ['command' => $command, 'status' => $result]);
        }
        return $outputLines;
    }
}
