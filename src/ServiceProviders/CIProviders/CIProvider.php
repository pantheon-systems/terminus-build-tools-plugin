<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\CIProviders;

use Pantheon\TerminusBuildTools\ServiceProviders\ProviderInterface;

/**
 * Holds state information destined to be registered with the CI service.
 */
interface CIProvider extends ProviderInterface
{
    /**
     * Return the URL to the main page on this CI provider for the specified project.
     */
    public function projectUrl(CIState $ci_env);

    /**
     * Return the text for the badge for this CI service.
     */
    public function badge(CIState $ci_env);

    /**
     * Configure the CI Server to test the provided project.
     */
    public function configureServer(CIState $ci_env);

    /**
     * Begin testing the project once it has been configured.
     */
    public function startTesting(CIState $ci_env);
}
