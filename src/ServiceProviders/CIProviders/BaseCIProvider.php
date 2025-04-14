<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\CIProviders;

use Psr\Log\LoggerAwareTrait;
use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;
use Robo\Config\Config;

/**
 * Provides a base set of functionality for CI Providers.
 */
abstract class BaseCIProvider
{
    use LoggerAwareTrait;

    protected $config;
    protected $providerEnvironment;
    protected $serviceName;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @return string
     */
    public function getServiceName()
    {
        return $this->serviceName;
    }

    public function getEnvironment()
    {
        if (!$this->providerEnvironment) {
            $this->providerEnvironment = (new ProviderEnvironment())
              ->setServiceName($this->serviceName);
        }
        return $this->providerEnvironment;
    }

    protected function findIntersecting($existing, $vars)
    {
        $intersecting = [];

        foreach ($existing as $row) {
            $key = $row['key'];
            if (array_key_exists($key, $vars)) {
                $intersecting[] = $key;
            }
        }
        return $intersecting;
    }

    public function getSecretValues() {
        return [
          'token' => $this->token()
        ];
    }
}