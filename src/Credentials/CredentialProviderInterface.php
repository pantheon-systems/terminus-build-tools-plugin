<?php

namespace Pantheon\TerminusBuildTools\Credentials;

/**
 * The credential manager stores and fetches credentials from a cache.
 * When necessary, it will prompt the user to provide a needed credential.
 */
interface CredentialProviderInterface
{
    /**
     * Determine whether or not a credential exists in the cache
     */
    public function has($id);

    /**
     * Fetch a credential from the cache
     */
    public function fetch($id);
}
