<?php

namespace Pantheon\TerminusBuildTools\API\GitLab;

use Pantheon\TerminusBuildTools\API\WebAPI;
use Pantheon\TerminusBuildTools\API\WebAPIInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialProviderInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialRequest;
use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;
use Pantheon\TerminusBuildTools\API\GitLab\GitLabAPI;
use Pantheon\TerminusBuildTools\ServiceProviders\ServiceTokenStorage;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

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
      $this->api = new GitLabAPI($this->getEnvironment());
      $this->api->setLogger($this->logger);
      $this->api->setGitLabUrl($this->GITLAB_URL);
    }
    return $this->api;
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
    // Tell the credential manager that we require one credential: the
    // GITLAB_TOKEN that will be used to authenticate with the GitLab server.
    $gitlabTokenRequest = new CredentialRequest(
      $this->tokenKey(),
      "Please generate a GitLab personal access token by visiting the page:\n\n    https://" . $this->getGitLabUrl() . "/profile/personal_access_tokens\n\n For more information, see:\n\n    https://" . $this->getGitLabUrl() . "/help/user/profile/personal_access_tokens.md.\n\n Give it the 'api' (required) scopes.",
      "Enter GitLab personal access token: ",
      '#^[0-9a-zA-Z\-]{20}$#',
      'GitLab authentication tokens should be 20-character strings containing only the letters a-z and digits (0-9). Please enter your token again.'
    );

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
      throw new \Exception('Could not determine authentication token for GitLab serivces. Please set ' . $tokenKey);
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
