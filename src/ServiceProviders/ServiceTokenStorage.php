<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders;

/**
 * An object that keeps track of the token for a service.
 *
 * For services with only one token, clients do not need to keep
 * track of the token name. For those that require multiple tokens
 * (e.g. bitbucket needs username and password / app password), the
 * key must be provided.
 */
interface ServiceTokenStorage
{
    /**
     * hasToken returns whether the storage object has a / the security token.
     *
     * @param string $key The token name. Optional; defaults to last key set.
     * @return bool
     */
    public function hasToken($key = false);

    /**
     * token returns a / the security token
     *
     * @param string $key The token name. Optional; defaults to last key set.
     * @return string
     */
    public function token($key = false);

    /**
     * setToken stores the given token for later use.
     */
    public function setToken($key, $token);
}
