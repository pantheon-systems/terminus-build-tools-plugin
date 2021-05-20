<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\GithubActions;

use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\BaseCIProvider;
use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CIProvider;
use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CIState;

use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;
use Pantheon\TerminusBuildTools\Task\Ssh\PrivateKeyReciever;
use Psr\Log\LoggerAwareInterface;

use Pantheon\TerminusBuildTools\Credentials\CredentialClientInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialProviderInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialRequest;

use Pantheon\TerminusBuildTools\API\GitHub\GitHubAPITrait;

/**
 * Manages the configuration of a project to be tested on Circle CI.
 */
class GithubActionsProvider extends BaseCIProvider implements CIProvider, LoggerAwareInterface, PrivateKeyReciever, CredentialClientInterface
{
    use GithubAPITrait;

    protected $serviceName = 'githubactions';

    public function infer($url)
    {
        return strpos($url, 'github.com') !== false;
    }

    public function projectUrl(CIState $ci_env)
    {
        $repositoryAttributes = $ci_env->getState('repository');
        return "https://github.com/{$repositoryAttributes->projectId()}";
    }

    protected function apiUrl(CIState $ci_env)
    {
        $repositoryAttributes = $ci_env->getState('repository');
        $target_project = $repositoryAttributes->projectId();

        return "https://api.github.com/repos/$target_project";
    }

    /**
     * Return the text for the badge for this CI service.
     */
    public function badge(CIState $ci_env)
    {
        $url = $this->projectUrl($ci_env) . '/actions/workflows/build_deploy_and_test.yml';
        return "[![Github Actions]($url/badge.svg)]($url)";
    }

    /**
     * Write the CI environment variables to the Circle "envrionment variables" configuration section.
     *
     * @param CIState $ci_env
     * @param Session $session TEMPORARY to be removed
     */
    public function configureServer(CIState $ci_env)
    {
        $this->logger->notice('Configure Github Actions');
        $this->setGithubActionsSecrets($ci_env);
        // @todo: Delete?
        //$this->onlyBuildPullRequests($ci_env);
    }

    protected function getPublicKey(CIState $ci_env)
    {
        $repositoryAttributes = $ci_env->getState('repository');
        $target_project = $repositoryAttributes->projectId();
        $public_key = $this->api()->request("repos/${target_project}/actions/secrets/public-key");
        return $public_key['key'];
    }

    protected function encryptSecret($secret, $public_key) {
      // @todo Encrypt secrets with libsodium.
      // https://docs.github.com/en/actions/reference/encrypted-secrets
      return $secret;
    }

    protected function setGithubActionsSecrets(CIState $ci_env)
    {
        $circle_url = $this->apiUrl($ci_env);
        $public_key = $this->getPublicKey($ci_env);
        $env = $ci_env->getAggregateState();
        foreach ($env as $key => $value) {
            $data = ['name' => $key, 'value' => $this->encryptSecret($value, $public_key)];
            if (empty($value)) {
                $this->logger->warning('Variable {key} empty: skipping.', ['key' => $key]);
            } else {
                // @todo Store secret.
                //$this->circleCIAPI($data, "$circle_url/envvar");
            }
        }
    }

    public function startTesting(CIState $ci_env)
    {
        // @todo: Should I do something?
        //$circle_url = $this->apiUrl($ci_env);
        //$this->circleCIAPI([], "$circle_url/follow");
    }

    public function addPrivateKey(CIState $ci_env, $privateKey)
    {
        $circle_url = $this->apiUrl($ci_env);
        $privateKeyContents = file_get_contents($privateKey);
        $data = [
            'hostname' => 'drush.in',
            'private_key' => $privateKeyContents,
        ];
        //$this->circleCIAPI($data, "$circle_url/ssh-key");
    }

}
