<?php

namespace Pantheon\TerminusBuildTools\API\GitLab;

use Pantheon\TerminusBuildTools\API\GitLab\GitLabAPI;
use Pantheon\TerminusBuildTools\API\WebAPIInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialProviderInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialRequest;
use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\RepositoryEnvironment;

/**
 * GitLabAPITrait provides access to the GitLabAPI, and manages
 * the credentials via a ServiceTokenStorage object
 */
trait GitLabAPITrait
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
    $api = new GitLabAPI($environment);
    $api->setLogger($this->logger);
    $api->setGitLabUrl($this->GITLAB_URL);
    return $api;
  }

  public function tokenKey()
  {
    return GitLabAPI::GITLAB_TOKEN;
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

  public function getGitLabUrl()
  {
    return GitLabAPI::GITLAB_URL_DEFAULT;
  }

  /**
   * @inheritdoc
   */
  public function credentialRequests()
  {
    $instructions = "Please generate a GitLab personal access token by visiting the page:\n\n    https://" . $this->getGitLabUrl() . "/profile/personal_access_tokens\n\n For more information, see:\n\n    https://" . $this->getGitLabUrl() . "/help/user/profile/personal_access_tokens.md.\n\n Give it the 'api', 'read_repository', and 'write_repository' (required) scopes.";

    $validation_message = 'GitLab authentication tokens should be 20 or 26 characters strings containing only the letters a-z and digits (0-9). Please enter your token again.';

    $could_not_authorize = 'Your provided authentication token could not be used to authenticate with the GitLab service. Please re-enter your credential.';

    // Tell the credential manager that we require one credential: the
    // GITLAB_TOKEN that will be used to authenticate with the GitLab server.
    $gitlabTokenRequest = (new CredentialRequest($this->tokenKey()))
        ->setInstructions($instructions)
        ->setPrompt("Enter GitLab personal access token: ")
        ->setValidateRegEx('#^[0-9a-zA-Z\-_]{20,26}$#')
        ->setValidationErrorMessage($validation_message)
        ->setValidationCallbackErrorMessage($could_not_authorize)
        ->setValidateFn(
            function ($token) {
                $tmpEnvironment = new RepositoryEnvironment();
                $tmpEnvironment->setToken(GitLabAPI::GITLAB_TOKEN, $token);
                $api = $this->createApi($tmpEnvironment);
                $userinfo = $api->request('api/v4/user');

                return true;
            }
        )
        ->setRequired(true);

    return [ $gitlabTokenRequest ];
  }

  /**
   * @inheritdoc
   */
  public function setCredentials(CredentialProviderInterface $credentials_provider)
  {
    // Since the `credentialRequests()` method declared that we need a
    // GITLAB_TOKEN credential, it will be available for us to copy from
    // the credentials provider when this method is called.
    $tokenKey = $this->tokenKey();
    $token = $credentials_provider->fetch($tokenKey);
    if (!$token) {
      throw new \Exception('Could not determine authentication token for GitLab services. Please set ' . $tokenKey);
    }
    $this->setToken($token);
  }

  /**
   * @inheritdoc
   */
  public function authenticatedUser()
  {
    $userData = $this->api()->request('api/v4/user');
    return $userData['username'];
  }
}
