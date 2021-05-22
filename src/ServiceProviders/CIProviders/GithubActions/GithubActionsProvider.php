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

use ParagonIE_Sodium_Compat;

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
        return $public_key;
    }

    protected function encryptSecret($secret, $public_key) {
        $encrypted = ParagonIE_Sodium_Compat::crypto_box_seal(utf8_encode($secret), base64_decode(utf8_encode($public_key)));
        return utf8_decode(base64_encode($encrypted));
    }

    protected function setGithubActionsSecrets(CIState $ci_env)
    {
        $github_api_url = $this->apiUrl($ci_env);
        $public_key = $this->getPublicKey($ci_env);
        $env = $ci_env->getAggregateState();
        foreach ($env as $key => $value) {
            if (empty($value)) {
                $this->logger->warning('Variable {key} empty: skipping.', ['key' => $key]);
            } else {
                $data = [
                    'encrypted_value' => $this->encryptSecret($value, $public_key['key']),
                    'key_id' => $public_key['key_id'],
                ];
                if ($key === 'GITHUB_TOKEN') {
                    $key = 'GH_TOKEN';
                }
                $url = $github_api_url . '/actions/secrets/' . $key;
                $this->api()->request($url, $data, 'PUT');
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
        $github_api_url = $this->apiUrl($ci_env);
        $public_key = $this->getPublicKey($ci_env);
        $privateKeyContents = file_get_contents($privateKey);
        $data = [
            'encrypted_value' => $this->encryptSecret($privateKeyContents, $public_key['key']),
            'key_id' => $public_key['key_id'],
        ];
        $url = $github_api_url . '/actions/secrets/SSH_PRIVATE_KEY';
        $this->api()->request($url, $data, 'PUT');

    }

}
