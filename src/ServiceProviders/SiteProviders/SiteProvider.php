<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\SiteProviders;

use Pantheon\TerminusBuildTools\ServiceProviders\ProviderInterface;

/**
 * The only kind of site provider we support is Pantheon.
 * We have an interface for uniformity though.
 */
interface SiteProvider extends ProviderInterface
{
    // TODO: Perhaps this should be part of the ProviderInterface
    public function getServiceName();

    // TODO: This should probably be part of the ProviderInterface too.
    public function getEnvironment();

    public function setMachineToken($token);
}
