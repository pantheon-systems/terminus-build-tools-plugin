<?php
/**
 * Terminus Plugin that contain a collection of commands useful during
 * the build step on a [Pantheon](https://www.pantheon.io) site that uses
 * a GitHub PR workflow.
 *
 * See README.md for usage information.
 */

namespace Pantheon\TerminusBuildTools\Commands;

use Consolidation\AnnotatedCommand\AnnotationData;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Workflow Wait Command
 */
class WorkflowWaitCommand extends BuildToolsBase
{
    /**
     * Wait for a workflow to complete. Usually this will be used to wait
     * for code commits, since Terminus will already wait for workflows
     * that it starts through the API.
     *
     * @command build:workflow:wait
     * @aliases workflow:wait
     * @param $site_env_id The pantheon site to wait for.
     * @param $description The workflow description to wait for. Optional; default is code sync.
     * @option start Ignore any workflows started prior to the start time (epoch)
     */
    public function workflowWait(
        $site_env_id,
        $description = '',
        $options = [
          'start' => 0,
        ])
    {
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $env_name = $env->getName();

        $startTime = $options['start'];
        if (!$startTime) {
            $startTime = time() - 60;
        }
        $this->waitForWorkflow($startTime, $site, $env_name, $description, $options['max']);
    }

    /**
     * @hook option build:workflow:wait
     */
    public function maxOption(Command $command, AnnotationData $annotationData)
    {
        $command->addOption('max', null, InputOption::VALUE_OPTIONAL, 'Maximum time in seconds to wait', BuildToolsBase::DEFAULT_WORKFLOW_TIMEOUT);
    }
}
