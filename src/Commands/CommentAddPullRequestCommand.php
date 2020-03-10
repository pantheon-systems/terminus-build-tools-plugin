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
 * Pull Request Comment Command
 */
class CommentAddPullRequestCommand extends BuildToolsBase
{

    /**
     * Add a comment to a specified PR on the repository.
     *
     * @authorize
     *
     * @command build:comment:add:pr
     */
    public function commentAddPullRequest(
        $options = [
            'pr_id' => '',
            'message' => '',
            'site_url' => ''
        ])
    {
        // Get current repository and commit
        $remoteUrlFromGit = exec('git config --get remote.origin.url');
        $prId = $options['pr_id'];
        if (empty($prId)) {
            throw new TerminusException( '--pr_id=<id> is required.' );
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
            $this->git_provider->commentOnPullRequest($targetProject, $prId, $message);
        } else {
            throw new TerminusException( '--message and/or --site_url are required.' );
        }
    }
}
