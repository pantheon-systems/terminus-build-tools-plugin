<?php

namespace Pantheon\TerminusBuildTools\API\GitHub;

use Pantheon\TerminusBuildTools\API\WebAPIInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialProviderInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialRequest;
use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\RepositoryEnvironment;

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
            $this->api = $this->createApi($this->getEnvironment());
        }
        return $this->api;
    }

    protected function createApi($environment)
    {
        $api = new GitHubAPI($environment);
        $api->setLogger($this->logger);
        return $api;
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
        $instructions = "Please generate a GitHub personal access token by visiting the page:\n\n    https://github.com/settings/tokens\n\n For more information, see:\n\n    https://help.github.com/articles/creating-an-access-token-for-command-line-use.\n\n Give it the 'repo' (required), 'workflow' (optional, needed if using Github Actions) and 'delete-repo' (optional) scopes.";

        $prompt = "Enter GitHub personal access token: ";

        $validation_message = 'GitHub authentication tokens should be 40-character strings containing only the letters a-f and digits (0-9). Please enter your token again.';

        $could_not_authorize = 'Your provided authentication token could not be used to authenticate with the GitHub service. Please re-enter your credential.';

        // Tell the credential manager that we require one credential: the
        // GITHUB_TOKEN that will be used to authenticate with the CircleCI server.
        $githubTokenRequest = (new CredentialRequest($this->tokenKey()))
            ->setInstructions($instructions)
            ->setPrompt($prompt)
            ->setValidateRegEx('#^[0-9a-zA-Z_]{40}$#')
            ->setValidationErrorMessage($validation_message)
            ->setValidationCallbackErrorMessage($could_not_authorize)
            ->setValidateFn(
                function ($token) {
                    $tmpEnvironment = new RepositoryEnvironment();
                    $tmpEnvironment->setToken(GitHubAPI::GITHUB_TOKEN, $token);
                    $api = $this->createApi($tmpEnvironment);
                    $userinfo = $api->request('user');

                    return true;
                }
            )
            ->setRequired(true);

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
            throw new \Exception('Could not determine authentication token for GitHub services. Please set ' . $tokenKey);
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
