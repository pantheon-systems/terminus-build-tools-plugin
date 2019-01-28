<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\GitLabCI;

use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CIProvider;
use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CIState;

use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;
use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\GitLab\GitLabProvider;
use Pantheon\TerminusBuildTools\Task\Ssh\PrivateKeyReciever;
use Pantheon\TerminusBuildTools\Task\Ssh\PublicKeyReciever;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

use Pantheon\TerminusBuildTools\Credentials\CredentialClientInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialProviderInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialRequest;
use Robo\Common\ConfigAwareTrait;
use Robo\Config\Config;

/**
 * Manages the configuration of a project to be tested on GitLabCI.
 */
class GitLabCIProvider implements CIProvider, LoggerAwareInterface, PrivateKeyReciever, CredentialClientInterface
{
    use LoggerAwareTrait;

    // We make this modifiable as individuals can self-host GitLab.
    public $GITLAB_URL;
    // Since GitLab and GitLabCI are so tightly coupled, use the Repository constants.
    const GITLAB_TOKEN = GitLabProvider::GITLAB_TOKEN;
    const GITLAB_CONFIG_PATH = GitLabProvider::GITLAB_CONFIG_PATH;
    const GITLAB_URL_DEFAULT = GitLabProvider::GITLAB_URL_DEFAULT;

    protected $gitlab_token;
    protected $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->GITLAB_URL = $config->get(self::GITLAB_CONFIG_PATH, self::GITLAB_URL_DEFAULT);
    }

    public function infer($url)
    {
        return strpos($url, $this->GITLAB_URL) !== false;
    }

    /**
     * Return 'true' if our token has been set yet.
     */
    public function hasToken()
    {
        return isset($this->gitlab_token);
    }

    /**
     * Set our token. This will be called via 'setCredentials()', which is
     * called by the provider manager.
     */
    public function setToken($gitlab_token)
    {
        $this->gitlab_token = $gitlab_token;
    }

    public function token()
    {
        return $this->gitlab_token;
    }

    /**
     * @inheritdoc
     */
    public function credentialRequests()
    {
        // Tell the credential manager that we require one credential: the
        // GITLAB_TOKEN that will be used to authenticate.
        $gitlabTokenRequest = new CredentialRequest(
            self::GITLAB_TOKEN,
            "Please generate a GitLab personal access token by visiting the page:\n\n    https://" . $this->GITLAB_URL . "/profile/personal_access_tokens\n\n For more information, see:\n\n    https://" . $this->GITLAB_URL . "/help/user/profile/personal_access_tokens.md.\n\n Give it the 'api' (required) scopes.",
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
        $tokenKey = self::GITLAB_TOKEN;
        $token = $credentials_provider->fetch($tokenKey);
        if (!$token) {
            throw new \Exception('Could not determine authentication token for GitLab serivces. Please set ' . $tokenKey);
        }
        $this->setToken($token);
    }

    public function projectUrl(CIState $ci_env)
    {
        $repositoryAttributes = $ci_env->getState('repository');
        return 'https://' . $this->GITLAB_URL . '/' . $repositoryAttributes->projectId();
    }

    protected function apiUrl(CIState $ci_env)
    {
        $repositoryAttributes = $ci_env->getState('repository');
        $apiRepositoryType = $repositoryAttributes->serviceName();
        $target_project = urlencode($repositoryAttributes->projectId());

        return "https://" . $this->GITLAB_URL . "/api/v4/projects/$target_project/variables";
    }

    /**
     * Return the text for the badge for this CI service.
     */
    public function badge(CIState $ci_env)
    {
        $url = $this->projectUrl($ci_env);
        return "[![GitLabCI]($url/bradges/master/build.svg?style=shield)]($url)";
    }

    /**
     * Write the CI environment variables to the GitLabCI "envrionment variables" configuration section.
     *
     * @param CIState $ci_env
     * @param Session $session TEMPORARY to be removed
     */
    public function configureServer(CIState $ci_env)
    {
        $this->logger->notice('Configure GitLab CI');
        $this->setGitLabCIEnvironmentVars($ci_env);
    }

    protected function setGitLabCIEnvironmentVars(CIState $ci_env)
    {
        $gitlab_url = $this->apiUrl($ci_env);
        $env = $ci_env->getAggregateState();
        foreach ($env as $key => $value) {
            $data = ['key' => $key, 'value' => $value];
            $this->gitlabCIAPI($data, $gitlab_url);
        }
    }

    public function startTesting(CIState $ci_env) {
        // Do nothing...it starts automatically.
    }

  public function addPrivateKey(CIState $ci_env, $privateKey)
    {
        // We need to set the SSH Key variable in GitLabCI
        $gitlab_url = $this->apiUrl($ci_env);
        $data = ['key' => 'SSH_PRIVATE_KEY', 'value' => file_get_contents($privateKey)];
        $this->gitlabCIAPI($data, $gitlab_url);
    }

    protected function gitlabCIAPI($data, $url, $method = 'GET')
    {
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => ProviderEnvironment::USER_AGENT,
        ];

        if ($this->hasToken()) {
            $headers['PRIVATE-TOKEN'] = $this->token();;
        }

        $guzzleParams = [
            'headers' => $headers,
        ];
        if (!empty($data) && ($method == 'GET')) {
            $method = 'POST';
            $guzzleParams['json'] = $data;
        }

        $this->logger->notice('Call GitLab API: {method} {uri}', ['method' => $method, 'uri' => $url]);

        $client = new \GuzzleHttp\Client();
        $res = $client->request($method, $url, $guzzleParams);
        $resultData = json_decode($res->getBody(), true);

        return $res->getStatusCode();
    }
}
