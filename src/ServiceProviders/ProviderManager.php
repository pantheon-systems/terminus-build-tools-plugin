<?php
namespace Pantheon\TerminusBuildTools\ServiceProviders;

use Pantheon\TerminusBuildTools\Credentials\CredentialClientInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialManager;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class ProviderManager implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected $credential_manager;
    protected $providers = [];

    public function __construct(CredentialManager $credential_manager)
    {
        $this->credential_manager = $credential_manager;
    }

    public function createProvider($providerClass)
    {
        $provider = new $providerClass();
        if ($provider instanceof LoggerAwareInterface) {
            $provider->setLogger($this->logger);
        }

        if ($provider instanceof CredentialClientInterface) {
            $this->credential_manager->add($provider->credentialRequests());
        }
        $this->providers[] = $provider;
    }

    public function credentialManager()
    {
        return $this->credential_manager;
    }

    public function validateCredentials()
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof CredentialClientInterface) {
                // TODO: verify that the credential manager has obtained
                // everything requested by the provider.
                $provider->setCredentials($this->credential_manager);
            }
        }
    }
}
