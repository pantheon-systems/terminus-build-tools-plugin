<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders;

/**
 * Store variables relevant to a provider.
 *
 * Use like an ArrayObject to cache transient variables.
 *
 * Use setToken / token to get / fetch variables that should be
 * persisted with the CI service.
 */
class ProviderEnvironment extends \ArrayObject implements ServiceTokenStorage
{
    const USER_AGENT = 'pantheon/terminus-build-tools-plugin';

    protected $token_key = 'TOKEN';
    protected $tokens = [];
    protected $serviceName;
    /** var string [] */
    protected $nonSecretVariales = [];

    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * @inheritdoc
     */
    public function hasToken($key = false)
    {
        if (!$key) {
            $key = $this->token_key;
        }
        return in_array($key, $this->tokens);
    }

    /**
     * @inheritdoc
     */
    public function token($key = false)
    {
        if (!$key) {
            $key = $this->token_key;
        }
        if (!isset($this[$key])) {
            return NULL;
        }
        return $this[$key];
    }

    /**
     * @inheritdoc
     */
    public function setToken($key, $token)
    {
        $this->token_key = $key;
        $this->tokens[] = $key;
        $this[$key] = $token;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function serviceName()
    {
        return $this->serviceName;
    }

    /**
     * @inheritdoc
     */
    public function setServiceName($serviceName)
    {
        $this->serviceName = $serviceName;
        return $this;
    }

    public function getPublicVariableKeys()
    {
        return $this->nonSecretVariales;
    }

    public function makeVariableValuePublic($key)
    {
        $this->nonSecretVariales[] = $key;
        return $this;
    }

    /**
     * Return only those variables that should be stored as environment
     * variables by the CI server.
     */
    public function ciState()
    {
        return $this->getArrayCopy();
    }

    public function getElements($keys)
    {
      return array_intersect_key($this->getArrayCopy(), array_combine($keys, $keys));
    }
}
