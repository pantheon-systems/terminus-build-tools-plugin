<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders;

/**
 * Holds state information destined to be registered with the git repository service.
 */
interface GitProvider
{
    // TODO: Perhaps there should be a base interface shared by GitProvider
    // and CIProvider. getEnvironment would then move there. The CIProvider
    // environment would just be empty at the moment, though.
    public function getEnvironment();

    /**
     * Create a repository
     *
     * @param $dir Local working copy of repository to create
     * @param $target Project name of the repository to create
     * @param $github_org Which org to create the project in; leave off to create a user repository.
     */
    public function createRepository($dir, $target, $github_org = '');

    /**
     * Push repository back to repository service.
     *
     * @param $dir Local working copy of repository to push
     * @param $target_project Project to push to; usually org/projectname
     */
    protected function pushRepository($dir, $target_project);
}
