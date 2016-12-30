<?php
/**
 * This command will manage secrets on a Pantheon site.
 *
 * See README.md for usage information.
 */

namespace Pantheon\TerminusSecrets\Commands;

use Consolidation\OutputFormatters\StructuredData\PropertyList;
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
    public function mergeBuildEnv($site_env_id)
    {
        $env_id = ''; // get env from $site_env_id
        $env_label = $env_id;

        if ($env_id == 'dev') {
            // terminus env:commit $TERMINUS_SITE.$TERMINUS_ENV --yes --message="Build assets for $TERMINUS_ENV_LABEL."
            return;
        }

        // Switch the dev site back to git mode. It should remain in git mode, though.
        // terminus connection:set $TERMINUS_SITE.$TERMINUS_ENV git

        // Replace the entire contentsof the master branch with the branch we just tested.
        passthru('git checkout master');
        passthru("git merge -q -m 'Merge build assets from test $env_label.' -X theirs $env_id");

        // Push our changes back to the dev environment, replacing whatever was there before.
        passthru('git push --force -q pantheon master');
    }

    /**
     * @command build-env:delete-oldest
     */
    public function deleteBuildEnv($site_id, $multidev_delete_pattern)
    {
        // Look up the oldest environments
        $oldestEnvironments = $this->oldestEnvironments($site_id, $multidev_delete_pattern);

        // Exit if there are no environments to delete

        // Go ahead and delete the oldest environments.
        foreach ($oldestEnvironments as $env_id) {
            // terminus multidev:delete $TERMINUS_SITE.$ENV_TO_DELETE --delete-branch --yes
        }
    }

    protected function oldestEnvironments($site_id, $multidev_delete_pattern)
    {
        // $(terminus env:list $TERMINUS_SITE --field=id | sort | grep "$MULTIDEV_DELETE_PATTERN" | sed -e '$d' | sed -e '$d')
    }
}
