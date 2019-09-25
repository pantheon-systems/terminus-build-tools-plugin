<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders;

use Pantheon\TerminusBuildTools\ServiceProviders\ProviderInterface;

/**
 * Holds state information destined to be registered with the git repository service.
 */
interface GitProvider extends ProviderInterface
{
    // TODO: Perhaps this should be part of the ProviderInterface
    public function getServiceName();

    // TODO: Perhaps there should be a base interface shared by GitProvider
    // and CIProvider. getEnvironment would then move there. The CIProvider
    // environment would just be empty at the moment, though.
    public function getEnvironment();

    /**
     * Return the name of the authenticated user.
     *
     * @return string
     */
    public function authenticatedUser();

    /**
     * Create a repository
     *
     * @param string $dir Local working copy of repository to create
     * @param string $target Project name of the repository to create
     * @param string $org Which org to create the project in; leave off to create a user repository.
     * @param bool $private Whether to make the repository private in github or not
     *
     * @return string Project created (usually org/target)
     */
    public function createRepository($dir, $target, $org = '', $private = false);

    /**
     * Push repository back to repository service. Note that, in essence,
     * this is simply a `git push` command; however, it also provides the
     * credentials for the push operation, and does not require a remote
     * be set for the target.
     *
     * @param string  $dir Local working copy of repository to push
     * @param string  $target_project Project to push to; usually org/projectname
     * @param boolean $use_ssh Skip setting the remote and only 'git push'.
     */
    public function pushRepository($dir, $target_project, $use_ssh = false);

    /**
     * Delete a repository from the repository service.
     *
     * @param string $project The project to delete (org/projectname)
     */
    public function deleteRepository($project);

    /**
     * Project URL to visit provided project in a web browser.
     *
     * @param string $target_project Project to generate browser URL for.
     *
     * @return string URL to target project.
     */
    public function projectURL($target_project);

    /**
     * Add a comment to a PR.
     *
     * @param string $target_project Project to comment on.
     * @param string $pr_id Id of the PR to comment on.
     * @param string $message The content of the comment.
     */
    public function commentOnPullRequest($target_project, $pr_id, $message);

    /**
     * Add a comment to a commit.
     *
     * @param string $target_project Project to comment on.
     * @param string $commit_hash SHA hash of the commit to comment on.
     * @param string $message The content of the comment.
     */
    public function commentOnCommit($target_project, $commit_hash, $message);

    /**
     * Return an array of PR-Number => branch-name for pull requests on a repo.
     *
     * @param string $target_project Project to check.
     * @param string $state Check for pull requests with this state - 'open' or 'closed'.
     */
    public function branchesForPullRequests($target_project, $state);

    /**
     * Alter the build metadata being written to Pantheon with any provider specific information.
     *
     * @param array $buildMetadata Build metadata to alter.
     */
    public function alterBuildMetadata(&$buildMetadata);

    /**
     * Return an associative array of values to be written to tokens.json build secrets.
     *
     * @return array
     */
    public function getSecretValues();

    /**
     * Verifies the repository can be connected to via SSH.
     *
     * @return boolean
     */
    public function verifySSHConnect();

}
