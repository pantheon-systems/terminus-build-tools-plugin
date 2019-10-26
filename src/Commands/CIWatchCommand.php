<?php

namespace Pantheon\TerminusBuildTools\Commands;

use Pantheon\Terminus\Exceptions\TerminusException;
use Symfony\Component\Console\Input\InputInterface;

/**
 * CI Watch Command
 */
class CIWatchCommand extends BuildToolsBase
{

    use \Pantheon\TerminusBuildTools\Task\CI\Tasks;

    /**
     * @var string
     */
    protected $target_project;

    /**
     * @var string
     */
    protected $url;

    /**
     * Initialize the default value for selected options.
     *
     * @hook init build:ci:watch
     */
    public function initOptionValues(InputInterface $input)
    {
        $this->log()->notice('build:ci:watch @hook init');

        // Get the site name argument. If there is none, skip init
        // and allow the command to fail with "not enough arguments"
        $site_name = $input->getArgument('site_name');
        if (empty($site_name)) {
            return;
        }

        // Fetch the build metadata
        $buildMetadata = $this->retrieveBuildMetadata("{$site_name}.dev");
        $url = $this->getMetadataUrl($buildMetadata);
        $this->url = $url;

        // Create a git repository service provider appropriate to the URL
        $this->git_provider = $this->inferGitProviderFromUrl($url);

        // Extract just the project id from the URL
        $target_project = $this->projectFromRemoteUrl($url);
        $this->target_project = $target_project;
        $this->git_provider->getEnvironment()->setProjectId($target_project);

        $ci_provider_class_or_alias = $this->selectCIProvider($this->git_provider->getServiceName(), '');

        $this->createCIProvider($ci_provider_class_or_alias);
    }

    /**
     * Ensure that the user has not supplied any parameters with invalid values.
     *
     * @hook validate build:ci:watch
     */
    public function validateCreateProject()
    {
        $this->log()->notice('build:ci:watch @hook validate');

        // Ensure that all of our providers are given the credentials they requested.
        $this->providerManager()->validateCredentials();
    }

    /**
     * Watches the most recent job for a CI provider to see how it finishes.
     *
     * @param string $site_name Site name
     * @option string $branch-name Check the latest pipeline on this branch.
     *
     * @authorize
     * @command build:ci:watch
     */
    public function watch(
        $site_name,
        $options = [
            'branch-name' => '',
        ]
    )
    {
        $ci_env = $this->getCIEnvironment([]);
        // Add the environment variables from the git provider to the CI environment.
        $ci_env->storeState('repository', $this->git_provider->getEnvironment());

        // TODO Fetch the default branch from Git provider.
        if (empty($options['branch-name'])) {
            $options['branch-name'] = 'master';
        }

        $ciProvider = $this->ci_provider;

        // Fetch all pipelines (associated with a specific branch) from provider.
        $pipelineId = $ciProvider->getMostRecentPipelineId($ci_env, $options['branch-name']);
        if (! $pipelineId) {
            throw new TerminusException('No pipelines found for branch \'{branch}\'.', [
                'branch' => $options['branch-name'],
            ] );
        }

        // With the most recent pipeline identified, make an API request every
        // 15 seconds to see if the pipeline passed successfully or failed.
        do {
            $status = $ciProvider->getPipelineStatus($ci_env, $pipelineId);
            // If the pipeline is pending, wait 15 seconds before fetching again.
            if ('pending' === $status) {
                $this->log()->notice('Pipeline was \'pending\'. Waiting 15 seconds before checking pipeline status again.');
                sleep(15);
            }
        } while('pending' === $status);

        if ('success' === $status) {
            $this->log()->notice('Pipeline {id} passed successfully.', [
                'id' => $pipelineId,
            ]);
            return;
        } elseif ('failed' === $status) {
            throw new TerminusException('Pipeline {id} was not successful.', [
                'id' => $pipelineId,
            ]);
        }
        throw new TerminusException('Pipeline status was not recognized.');
    }

}
