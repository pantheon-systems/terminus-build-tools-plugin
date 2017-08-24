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

    protected function availableProviders()
    {
        // TODO: create some way to register providers. Plugin plugins?
        return [
            '\Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CircleCI\CircleCIProvider',
            '\Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\GitHub\GitHubProvider',
            '\Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\Bitbucket\BitbucketProvider',
        ];
    }

    public function inferProvider($url, $expectedInterface)
    {
        $available_providers = $this->availableProviders();

        foreach ($available_providers as $provider) {
            $providerClass = new \ReflectionClass($provider);
            if ($providerClass->implementsInterface($expectedInterface)) {
                $providerInstance = new $provider();
                if ($providerInstance->infer($url)) {
                    $this->initializeProvider($providerInstance);
                    return $providerInstance;
                }
            }
        }
    }

    protected function lookupProvider($alias)
    {
        $available_providers = $this->availableProviders();

        // Only compare the alphanumeric parts of the provided alias.
        // i.e. --ci=circle-ci is the same as --ci=circleci
        $test_alias = preg_replace('#[^a-z0-9]#', '', $alias);

        // Allow providers to be specified
        foreach ($available_providers as $provider) {
            $provider_class = basename(strtr($provider, '\\', '/'));
            if (stristr($provider_class, $test_alias) !== false) {
                return $provider;
            }
        }

        // Nothing found? Return our input value.
        return $alias;
    }

    public function createProvider($providerClass, $expectedInterface)
    {
        $providerClass = $this->lookupProvider($providerClass);
        if (!class_exists($providerClass)) {
            throw new \Exception("Could not load class $providerClass");
        }
        $provider = new $providerClass();
        if (!$provider instanceof $expectedInterface) {
            throw new \Exception("Requested provider $providerClass does not implement required interface $expectedInterface");
        }
        return $this->initializeProvider($provider);
    }

    protected function initializeProvider($provider)
    {
        if ($provider instanceof LoggerAwareInterface) {
            $provider->setLogger($this->logger);
        }

        if ($provider instanceof CredentialClientInterface) {
            $this->credential_manager->add($provider->credentialRequests());
        }
        $this->providers[] = $provider;

        return $provider;
    }

    public function credentialManager()
    {
        return $this->credential_manager;
    }

    public function validateCredentials()
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof CredentialClientInterface) {
                // Allow the provider to fetch whichever credentials
                // are needed from the credential manager. If it cannot
                // get everything it needs, it should throw.
                $provider->setCredentials($this->credential_manager);
            }
        }
    }
}
