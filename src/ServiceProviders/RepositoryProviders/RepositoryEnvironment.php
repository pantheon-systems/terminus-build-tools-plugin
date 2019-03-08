<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders;

use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;

class RepositoryEnvironment extends ProviderEnvironment
{
    protected $projectId;

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

    /**
     * @inheritdoc
     */
    public function ciState()
    {
        return $this->getElements($this->tokens);
    }
}
