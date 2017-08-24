<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders;

use Pantheon\TerminusBuildTools\ServiceProviders\ProviderInterface;

/**
 * Holds state information destined to be registered with the git repository service.
 */
interface GitProvider extends ProviderInterface
{
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
     * @param $dir Local working copy of repository to create
     * @param $target Project name of the repository to create
     * @param $github_org Which org to create the project in; leave off to create a user repository.
     */
    public function createRepository($dir, $target, $github_org = '');

    /**
     * Push repository back to repository service. Note that, in essence,
     * this is simply a `git push` command; however, it also provides the
     * credentials for the push operation, and does not require a remote
     * be set for the target.
     *
     * @param $dir Local working copy of repository to push
     * @param $target_project Project to push to; usually org/projectname
     */
    public function pushRepository($dir, $target_project);

    /**
     * Delete a repository from the repository service.
     *
     * @param string $project The project to delete (org/projectname)
     */
    public function deleteRepository($project);
}
