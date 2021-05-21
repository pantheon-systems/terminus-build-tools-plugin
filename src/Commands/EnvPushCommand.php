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
 * Env Push Command
 */
class EnvPushCommand extends BuildToolsBase
{
    /**
     * Push code to a specific Pantheon site and environment that already exists.
     *
     * @command build:env:push
     * @aliases build-env:push-code
     *
     * @param string $site_env_id Site and environment to push to. May be any dev or multidev environment.
     * @param string $repositoryDir Code to push. Defaults to cwd.
     * @option label What to name the environment in commit comments
     * @option message Commit message to include when committing assets to Pantheon
     * @option no-git-force set this flag to omit the --force flag from 'git add' and 'git push'
     */
    public function pushCode(
        $site_env_id,
        $repositoryDir = '',
        $options = [
          'label' => '',
          'message' => '',
          'no-git-force' => FALSE,
        ])
    {
        return $this->pushCodeToPantheon($site_env_id, '', $repositoryDir, $options['label'], $options['message'], $options['no-git-force']);
    }
}
