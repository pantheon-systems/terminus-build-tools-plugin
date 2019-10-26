<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\BitbucketPipelines;

use Pantheon\TerminusBuildTools\API\Bitbucket\BitbucketAPITrait;
use Pantheon\TerminusBuildTools\Credentials\CredentialClientInterface;
use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\BaseCIProvider;
use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CIProvider;
use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CIState;
use Pantheon\TerminusBuildTools\Task\Ssh\KeyPairReciever;
use Psr\Log\LoggerAwareInterface;

/**
 * Manages the configuration of a project to be tested on BitbucketPipelines.
 */
class BitbucketPipelinesProvider extends BaseCIProvider implements CIProvider, LoggerAwareInterface, KeyPairReciever, CredentialClientInterface
{
    use BitbucketAPITrait;

    protected $serviceName = 'bitbucket-pipelines';

    public function infer($url)
    {
        return strpos($url, 'bitbucket.org') !== false;
    }

    public function projectUrl(CIState $ci_env)
    {
        $repositoryAttributes = $ci_env->getState('repository');
        // TODO: Fix this url. Point to the Bitbucket pipelines UI
        return "https://bitbucket.org/{$repositoryAttributes->projectId()}";
    }

    /**
     * Return the text for the badge for this CI service.
     */
    public function badge(CIState $ci_env)
    {
        $url = $this->projectUrl($ci_env);
        $repositoryAttributes = $ci_env->getState('repository');
        $projectId = $repositoryAttributes->projectId();
        return "[![Bitbucket Pipelines](https://img.shields.io/bitbucket/pipelines/$projectId.svg]($url/addon/pipelines/home)";
    }

    /**
     * Write the CI environment variables to the Bitbucket Pipelines "repository variables" configuration section.
     *
     * @param CIState $ci_env
     * @param Session $session TEMPORARY to be removed
     */
    public function configureServer(CIState $ci_env)
    {
        $this->logger->notice('Configure Bitbucket Pipelines');
        $this->setBitbucketPipelinesEnvironmentVars($ci_env);
    }

    protected function targetRepositoryBaseUrl(CIState $ci_env)
    {
        $repositoryAttributes = $ci_env->getState('repository');
        $target_project = $repositoryAttributes->projectId();

        return "repositories/$target_project";
    }

    protected function setBitbucketPipelinesEnvironmentVars(CIState $ci_env)
    {
        $repoApiUrl = $this->targetRepositoryBaseUrl($ci_env);

        $env = $ci_env->getAggregateState();
        foreach ($env as $key => $value) {
            $secured = $ci_env->isVariableValueSecret($key);
            $data = ['key' => $key, 'value' => $value, 'secured' => $secured];
            if (empty($value)) {
                $this->logger->warning('Variable {key} empty: skipping.', ['key' => $key]);
            } else {
                // TODO: remove debugging message
                $this->logger->notice('Configure Bitbucket environment variable ' . var_export($data, true));
                // Temporary: catch and eat errors without stopping the command
                try
                {
                    $this->api()->request("$repoApiUrl/pipelines_config/variables/", $data);
                } catch (\Exception $e) {
                    $this->logger->error($e->getMessage());
                }
            }
        }
    }

    public function getEnvironmentVars(CIState $ci_env)
    {
        $repoApiUrl = $this->targetRepositoryBaseUrl($ci_env);
        return $this->api()->request("$repoApiUrl/pipelines_config/variables/");
    }

    public function startTesting(CIState $ci_env)
    {
        $repoApiUrl = $this->targetRepositoryBaseUrl($ci_env);

        $this->logger->notice('Start testing with Bitbucket Pipelines');
        $data = ['enabled' => true];

        // Temporary: catch and eat errors without stopping the command
        try
        {
            // Enable Pipelines for the project.
            $this->api()->request("$repoApiUrl/pipelines_config", $data, 'PUT');
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }

        // @TODO: verify that the custom "clu" task exists in yml first?
        $data = [
          'enabled' => true,
          'cron_pattern' => '0 0 4 * * ? *',
          'target' => [
            'type' => 'pipeline_ref_target',
            'ref_type' => 'branch',
            'ref_name' => 'master',
            'selector' => [
              'type' => 'custom',
              'pattern' => 'clu'
            ]
          ]
        ];
        try {
            // Enable CLU scheduled task.
            $this->api()->request("$repoApiUrl/pipelines_config/schedules/", $data, 'POST');
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }

    public function addKeyPair(CIState $ci_env, $publicKey, $privateKey)
    {
        $repoApiUrl = $this->targetRepositoryBaseUrl($ci_env);

        $this->logger->notice('add ssh key pair to Bitbucket Pipelines');
        // We need to set the SSH Key variable in Bitbucket Pipelines
        $data = ['private_key' => file_get_contents($privateKey), 'public_key' => file_get_contents($publicKey)];

        // Temporary: catch and eat errors without stopping the command
        try
        {
            $this->api()->request("$repoApiUrl/pipelines_config/ssh/key_pair", $data, 'PUT');
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }

    public function getMostRecentPipelineId(CIState $ci_env, $branchName)
    {
        $repoApiUrl = $this->targetRepositoryBaseUrl($ci_env);
        // BitBucket defaults to ascending order, we need to sort
        // by -created_on to get the descding order
        // We can also target the branch and specific fields in the result
        // See https://community.atlassian.com/t5/Bitbucket-questions/I-would-like-a-way-to-know-the-last-status-of-a-pipeline-for/qaq-p/1135687#M44752
        $pipelines = $this->api()->request("$repoApiUrl/pipelines/?sort=-created_on&target.branch=" . urlencode($branchName), [], 'GET');
        if (empty($pipelines)) {
            return FALSE;
        }
        return $pipelines['values'][0]['uuid'];
    }

    /**
     * @return string Must be one of 'success', 'pending', or 'failed'.
     */
    public function getPipelineStatus(CIState $ci_env, $pipelineId)
    {
        $repoApiUrl = $this->targetRepositoryBaseUrl($ci_env);
        $pipeline = $this->api()->request(sprintf("$repoApiUrl/pipelines/%s", $pipelineId), [], 'GET');
        if (empty($pipeline)) {
            return FALSE;
        }
        if( 'COMPLETED' !== $pipeline['state']['name'] ) {
            return 'pending';
        }
        if( 'IN_PROGRESS' === $pipeline['state']['name'] ){
            return 'pending';
        }
        switch( $pipeline['state']['result']['name'] ) {
            case 'FAILED':
                return 'failed';
                break;
            case 'SUCCESSFUL':
                return 'success';
                break;
        }
        return false;
    }
}
