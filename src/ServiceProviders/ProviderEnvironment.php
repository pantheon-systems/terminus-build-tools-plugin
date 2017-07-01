<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders;

/**
 * Store variables relevant to a provider.
 */
class ProviderEnvironment extends \ArrayObject
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
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
