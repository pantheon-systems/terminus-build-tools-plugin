<?php

namespace Pantheon\TerminusBuildTools\Commands;

use Pantheon\Terminus\Exceptions\TerminusException;

/**
 * CI Watch Command
 */
class CIWatchCommand extends BuildToolsBase
{

    /**
     * @var string
     */
    protected $target_project;

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

        $buildMetadata = $this->retrieveBuildMetadata("$site_name.dev");
        $url = $this->getMetadataUrl($buildMetadata);
        $this->target_project = $this->projectFromRemoteUrl($url);
        $this->inferGitProviderFromUrl($url);

        // Ensure that credentials for the Git provider are available.
        $this->providerManager()->validateCredentials();
        $ciProvider = $this->providerManager()->inferProvider($url, 'Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CIProvider');
        $ci_env = $this->getCIEnvironment([]);
        $repository = $this->git_provider->getEnvironment();
        $repository->setProjectId($this->target_project);
        $ci_env->storeState('repository', $repository);

        // TODO Fetch the default branch from Git provider.
        if (empty($options['branch-name'])) {
            $options['branch-name'] = 'master';
        }

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
