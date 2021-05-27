<?php
namespace Pantheon\TerminusBuildTools\ServiceProviders;

use Pantheon\TerminusBuildTools\Credentials\CredentialClientInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialManager;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Robo\Config\Config;
use Symfony\Component\Console\Input\InputInterface;

class ProviderManager implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected $credential_manager;
    protected $config;
    protected $providers = [];

    public function __construct(CredentialManager $credential_manager, Config $config)
    {
        $this->credential_manager = $credential_manager;
        $this->config = $config;
    }

    public function availableProviders()
    {
        return [
            '\Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CircleCI\CircleCIProvider',
            '\Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\GitHub\GitHubProvider',
            '\Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\GithubActions\GithubActionsProvider',
            '\Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\Bitbucket\BitbucketProvider',
            '\Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\BitbucketPipelines\BitbucketPipelinesProvider',
            '\Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\GitLab\GitLabProvider',
            '\Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\GitLabCI\GitLabCIProvider',
            '\Pantheon\TerminusBuildTools\ServiceProviders\SiteProviders\PantheonProvider',
        ];
    }

    public function inferProvider($url, $expectedInterface)
    {
        $available_providers = $this->availableProviders();

        foreach ($available_providers as $provider) {
            $providerClass = new \ReflectionClass($provider);
            if ($providerClass->implementsInterface($expectedInterface)) {
                $providerInstance = new $provider($this->config);
                if ($providerInstance->infer($url)) {
                    $this->initializeProvider($providerInstance);
                    return $providerInstance;
                }
            }
        }
    }

    protected function lookupProvider($alias, $expectedInterface)
    {
        $available_providers = $this->availableProviders();

        // Only compare the alphanumeric parts of the provided alias.
        // i.e. --ci=circle-ci is the same as --ci=circleci
        $test_alias = preg_replace('#[^a-z0-9]#', '', $alias);

        // Bail early on invalid input
        if (empty($test_alias)) {
            return $alias;
        }

        // Allow providers to be specified
        foreach ($available_providers as $provider) {
            $reflectionClass = new \ReflectionClass($provider);
            if ($reflectionClass->implementsInterface($expectedInterface)) {
                $provider_class = basename(strtr($provider, '\\', '/'));
                if (stristr($provider_class, $test_alias) !== false) {
                    return $provider;
                }
            }
        }

        // Nothing found? Return our input value.
        return $alias;
    }

    public function createProvider($providerClass, $expectedInterface)
    {
        $providerClass = $this->lookupProvider($providerClass, $expectedInterface);
        if (!class_exists($providerClass)) {
            throw new \Exception("Could not load class $providerClass");
        }
        $provider = new $providerClass($this->config);
        if (!$provider instanceof $expectedInterface) {
            throw new \Exception("Requested provider $providerClass does not implement required interface $expectedInterface");
        }
        return $this->initializeProvider($provider);
    }

    public function initializeProvider($provider)
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

    /**
     * Give objects an opportunity to initialize themselves from the cli options
     */
    public function setFromOptions(InputInterface $input)
    {
        $this->credentialManager()->setFromOptions($input);
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

    public function getProviders()
    {
        return $this->providers;
    }
}
