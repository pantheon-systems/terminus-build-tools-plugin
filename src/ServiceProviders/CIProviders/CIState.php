<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\CIProviders;

use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;

/**
 * Holds state information destined to be registered with the CI service.
 *
 * For every provider that needs to store state in the CI service, a
 * "ProviderEnvironment" should be provided to this class via the
 * 'storeState' method. The '$owner' parameter is an arbitrary identifier.
 *
 * The CI provider will ask for all of the necessary state via the
 * 'getAggregateState()' method.  Every ProviderEnvironment has a method
 * 'ciState()' that will return the environment variables (key:value pairs)
 * that should be stored in the CI provier's configuration.
 */
class CIState
{
    /** var ProviderEnvironment[] */
    protected $state = [];

    public function get($owner, $key, $default)
    {
        return isset($this->state[$owner][$key]) ? $this->state[$owner][$key] : $default;
    }

    public function set($owner, $key, $value)
    {
        if (!isset($this->state[$owner])) {
            throw new \Exception("Tried to set state for a nonexistant owner '$owner'.");
        }
        $this->state[$owner][$key] = $value;
    }

    /**
     * Store state for some provider.
     *
     * @param ProviderEnvironment $state
     */
    public function storeState($owner, ProviderEnvironment $state)
    {
        $this->state[$owner] = $state;
    }

    /**
     * @return ProviderEnvironment
     */
    public function getState($owner)
    {
        return $this->state[$owner];
    }

    /**
     * @return array
     */
    public function getAggregateState()
    {
        return array_reduce(
            $this->state,
            function ($carry, $item) {
                return array_merge($carry, $item->ciState());
            },
            []
        );
    }
}
