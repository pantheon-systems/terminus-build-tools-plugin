<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\ObjectModel;

interface RepositoryInfoInterface
{
    /**
     * Return the short name of the repository.
     *
     * @return string
     */
    public function name();

    /**
     * Return the user/projectname or org/projectname for the repsoitory
     */
    public function project();

    /**
     * Return the repository id.
     *
     * @return string
     */
    public function id();

    /**
     * Return the name of the repository owner.
     *
     * @return string
     */
    public function owner();

    /**
     * Return all available user info about the repository owner.
     *
     * @return UserInfo
     */
    public function ownerInfo();

    /**
     * Return the URL of the project page where this project may be viewed
     *
     * @return string
     */
    public function projectPageUrl();

    /**
     * Return the URL that can be used to clone the git respository.
     *
     * @return string
     */
    public function repositoryUrl();
}
