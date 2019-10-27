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

    /**
     * Get the most recent pipeline/workflow ID, filtered by branch
     * @return string|int ID of the most recent pipelone of the given branch
     */
    public function getMostRecentPipelineId(CIState $ci_env, $branchName);

    /**
     * Get the status of a pipeline/workflow by ID
     * @return string Must be one of 'success', 'pending', or 'failed'.
     */
    public function getPipelineStatus(CIState $ci_env, $pipelineId);
}
