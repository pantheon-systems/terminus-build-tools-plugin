<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders;

use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;

class RepositoryEnvironment extends ProviderEnvironment
{
    protected $token_key = 'TOKEN';
    protected $serviceName;
    protected $projectId;

    public function hasToken()
    {
        return isset($this[$this->token_key]);
    }

    public function token()
    {
        return $this[$this->token_key];
    }

    public function setToken($key, $token)
    {
        $this->token_key = $key;
        $this[$key] = $token;
        return $this;
    }

    public function serviceName()
    {
        return $this->serviceName;
    }

    public function setServiceName($serviceName)
    {
        $this->serviceName = $serviceName;
        return $this;
    }

    /**
     * Project identifier (org/projectname)
     */
    public function projectId()
    {
        return $this->projectId;
    }

    /**
     * Set the project ID for this repository on the repository service.
     * Usually this will be 'org/projectname'.
     */
    public function setProjectId($projectId)
    {
        $this->projectId = $projectId;
        return $this;
    }

    public function ciState()
    {
        return $this->getElements([$this->token_key]);
    }
}
