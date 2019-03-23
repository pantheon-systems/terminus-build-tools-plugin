<?php

namespace Pantheon\TerminusBuildTools\API\GitHub;

use Pantheon\TerminusBuildTools\API\WebAPI;
use Pantheon\TerminusBuildTools\API\WebAPIInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialProviderInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialRequest;
use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;
use Pantheon\TerminusBuildTools\ServiceProviders\ServiceTokenStorage;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * BitbucketAPITrait provides access to the BitbucketAPI, and manages
 * the credentials via a ServiceTokenStorage object
 */
trait GitHubAPITrait
{
    /** @var WebAPIInterface */
    protected $api;

    public function api()
    {
        if (!$this->api) {
            $this->api = new GitHubAPI($this->getEnvironment());
            $this->api->setLogger($this->logger);
        }
        return $this->api;
    }

    public function tokenKey()
    {
        return GitHubAPI::GITHUB_TOKEN;
    }

    public function hasToken($key = false)
    {
        $repositoryEnvironment = $this->getEnvironment();
        return $repositoryEnvironment->hasToken($key);
    }

    public function token($key = false)
    {
        $repositoryEnvironment = $this->getEnvironment();
        return $repositoryEnvironment->token($key);
    }

    public function setToken($token)
    {
        $repositoryEnvironment = $this->getEnvironment();
        $repositoryEnvironment->setToken($this->tokenKey(), $token);
    }

    /**
     * @inheritdoc
     */
    public function credentialRequests()
    {
        // Tell the credential manager that we require one credential: the
        // GITHUB_TOKEN that will be used to authenticate with the CircleCI server.
        $githubTokenRequest = new CredentialRequest(
            $this->tokenKey(),
            "Please generate a GitHub personal access token by visiting the page:\n\n    https://github.com/settings/tokens\n\n For more information, see:\n\n    https://help.github.com/articles/creating-an-access-token-for-command-line-use.\n\n Give it the 'repo' (required) and 'delete-repo' (optional) scopes.",
            "Enter GitHub personal access token: ",
            '#^[0-9a-fA-F]{40}$#',
            'GitHub authentication tokens should be 40-character strings containing only the letters a-f and digits (0-9). Please enter your token again.'
        );

        return [ $githubTokenRequest ];
    }

    /**
     * @inheritdoc
     */
    public function setCredentials(CredentialProviderInterface $credentials_provider)
    {
        // Since the `credentialRequests()` method declared that we need a
        // GITHUB_TOKEN credential, it will be available for us to copy from
        // the credentials provider when this method is called.
        $tokenKey = $this->tokenKey();
        $token = $credentials_provider->fetch($tokenKey);
        if (!$token) {
            throw new \Exception('Could not determine authentication token for GitHub serivces. Please set ' . $tokenKey);
        }
        $this->setToken($token);
    }

    /**
     * @inheritdoc
     */
    public function authenticatedUser()
    {
        $userData = $this->api()->request('user');
        return $userData['login'];
    }
}
