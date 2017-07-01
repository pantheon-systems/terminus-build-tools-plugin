<?php

namespace Pantheon\TerminusBuildTools\Credentials;

/**
 * A credential client is an object (e.g. a provider) that needs credentials
 * for some purpose. It is possible that one client may need multiple
 * credentials.
 */
interface CredentialClientInterface
{
    /**
     * Return a list of credential request objects, one for each
     * credential needed by this object.
     *
     * @return CredentialRequestInterface[]
     */
    public function credentialRequests();

    /**
     * Set the credentials needed. The client should call $provider->fetch()
     * for each credential that is desired. Every credential requested by
     * 'credentialRequests' should be available at the time 'setCredentials'
     * is called.
     */
    public function setCredentials(CredentialProviderInterface $provider);
}
