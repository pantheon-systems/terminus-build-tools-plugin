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
 * Commit Comment Command
 */
class CommentAddCommitCommand extends BuildToolsBase
{

    /**
     * Add a comment to the latest commit on the repository.
     *
     * @authorize
     *
     * @command build:comment:add:commit
     */
    public function commentAddCommit(
        $options = [
            'sha' => '',
            'message' => '',
            'site_url' => ''
        ])
    {
        // Get current repository and commit
        $remoteUrlFromGit = exec('git config --get remote.origin.url');
        $commitHash = $options['sha'];
        if (empty($commitHash)) {
            $commitHash = exec('git rev-parse HEAD');
        }

        // Create a Git repository service provider appropriate to the URL
        $this->inferGitProviderFromUrl($remoteUrlFromGit);

        // Ensure that credentials for the Git provider are available
        $this->providerManager()->validateCredentials();

        // Compile message
        if (!empty($options['site_url'])) {
            $message = "[![Visit Site](https://raw.githubusercontent.com/pantheon-systems/terminus-build-tools-plugin/master/assets/images/visit-site-36.png)](".$options['site_url'].")\n\n".$options['message'];
        } else {
            $message = $options['message'];
        }

        if (!empty($message)) {
            // Submit message
            $targetProject = $this->projectFromRemoteUrl($remoteUrlFromGit);
            $this->git_provider->commentOnCommit($targetProject, $commitHash, $message);
        } else {
            throw new TerminusException( '--message and/or --site_url are required.' );
        }
    }
}
