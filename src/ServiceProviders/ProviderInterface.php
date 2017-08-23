<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders;

/**
 * Holds state information destined to be registered with the git repository service.
 */
interface ProviderInterface
{
    /**
     * Used to infer the provider to create from an identifier. Return
     * 'true' if this provider identifies with the URL (e.g. GithubProvider
     * expects the url to be for github.com).
     */
    public function infer($url);
}

