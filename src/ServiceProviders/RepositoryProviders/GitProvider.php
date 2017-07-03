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
}
