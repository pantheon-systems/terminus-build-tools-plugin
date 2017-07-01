<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\CIProviders;

use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;

/**
 * Holds state information destined to be registered with the CI service.
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
