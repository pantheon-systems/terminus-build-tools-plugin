<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\GitLab;

use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusBuildTools\Credentials\CredentialClientInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialProviderInterface;
use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\GitProvider;
use Pantheon\TerminusBuildTools\Credentials\CredentialRequest;
use Pantheon\TerminusBuildTools\Utility\ExecWithRedactionTrait;
use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\RepositoryEnvironment;
use Robo\Common\ConfigAwareTrait;
use Robo\Config\Config;

/**
 * Encapsulates access to GitLab through git and the GitLab API.
 */
class GitLabProvider implements GitProvider, LoggerAwareInterface, CredentialClientInterface
{
    use LoggerAwareTrait;
    use ExecWithRedactionTrait;

    const SERVICE_NAME = 'gitlab';
    // We make this modifiable as individuals can self-host GitLab.
    public $GITLAB_URL;
    const GITLAB_TOKEN = 'GITLAB_TOKEN';
    const GITLAB_CONFIG_PATH = 'command.build.provider.git.gitlab_url';
    const GITLAB_URL_DEFAULT = 'gitlab.com';
    protected $config;

    protected $repositoryEnvironment;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->GITLAB_URL = $config->get(self::GITLAB_CONFIG_PATH, self::GITLAB_URL_DEFAULT);
    }

    public function infer($url)
    {
        return strpos($url, $this->GITLAB_URL) !== false;
    }

    public function getEnvironment()
    {
        if (!$this->repositoryEnvironment) {
            $this->repositoryEnvironment = (new RepositoryEnvironment())
            ->setServiceName(self::SERVICE_NAME);
        }
        return $this->repositoryEnvironment;
    }

    public function tokenKey()
    {
        return self::GITLAB_TOKEN;
    }

    public function hasToken()
    {
        $repositoryEnvironment = $this->getEnvironment();
        return $repositoryEnvironment->hasToken();
    }

    public function token()
    {
        $repositoryEnvironment = $this->getEnvironment();
        return $repositoryEnvironment->token();
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
        // GITLAB_TOKEN that will be used to authenticate.
        $gitlabTokenRequest = new CredentialRequest(
            $this->tokenKey(),
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
        $userData = $this->gitLabAPI('api/v4/user');
        return $userData['username'];
    }

    /**
     * @inheritdoc
     */
    public function createRepository($local_site_path, $target, $gitlab_org = '')
    {
        $createRepoUrl = "api/v4/projects";
        $target_org = $gitlab_org;
        if (empty($gitlab_org)) {
            $target_org = $this->authenticatedUser();
            $postData = ['name' => $target];
        }
        else {
            // We need to look up the namespace ID.
            $group = $this->gitLabAPI('api/v4/groups/' . urlencode($gitlab_org));
            if (!empty($group)) {
                $postData = ['name' => $target, 'namespace_id' => $group['id']];
            }
            else {
                $postData = ['name' => $target];
            }
        }
        $target_project = "$target_org/$target";

        // Create a GitLab repository
        $this->logger->notice('Creating repository {repo}', ['repo' => $target_project]);

        $result = $this->gitLabAPI($createRepoUrl, $postData);

        // Create a git repository. Add an origin just to have the data there
        // when collecting the build metadata later. We use the 'pantheon'
        // remote when pushing.
        // TODO: Do we need to remove $local_site_path/.git? (-n in create-project should obviate this need) We preserve this here because it may be user-provided via --preserve-local-repository
        if (!is_dir("$local_site_path/.git")) {
            $this->execGit($local_site_path, 'init');
        }
        // TODO: maybe in the future we will not need to set this?
        $this->execGit($local_site_path, "remote add origin " . $result['ssh_url_to_repo']);

        return $result['path_with_namespace'];
    }

    /**
     * @inheritdoc
     */
    public function pushRepository($dir, $target_project)
    {
        $this->execGit($dir, 'push --progress https://oauth2:{token}@{gitlab_url}/{target}.git master', ['token' => $this->token(), 'gitlab_url' => $this->GITLAB_URL, 'target' => $target_project], ['token']);
    }

    /**
     * @inheritdoc
     */
    public function deleteRepository($project)
    {
        $deleteRepoUrl = "api/v4/projects/" . urlencode($project);
        $this->gitLabAPI($deleteRepoUrl, [], 'DELETE');
    }

    /**
     * @inheritdoc
     */
    public function projectURL($target_project)
    {
        return 'https://' . $this->GITLAB_URL . '/' . $target_project;
    }

    /**
     * @inheritdoc
     */
    public function commentOnCommit($target_project, $commit_hash, $message)
    {
        // We need to check and see if a MR exists for this commit.
        $mrs = $this->gitLabAPI("api/v4/projects/" . urlencode($target_project) . "/merge_requests?state=opened");
        $url = null;
        $data = [];
        foreach ($mrs as $mr) {
            if ($mr['sha'] == $commit_hash) {
                $url = "api/v4/projects/" . urlencode($target_project) . "/merge_requests/" . $mr['iid'] . "/notes";
                $data = [ 'body' => $message ];
                break;
            }
        }
        if (is_null($url)) {
            $url = "api/v4/projects/" . urlencode($target_project) . "/repository/commits/" . $commit_hash . "/comments";
            $data = [ 'note' => $message ];
        }

        $this->gitLabAPI($url, $data);
    }

    protected function gitLabAPI($uri, $data = [], $method = 'GET')
    {
        $url = "https://" . $this->GITLAB_URL . "/" . $uri;

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

        $this->logger->notice('Call GitLab API: {method} {uri}', ['method' => $method, 'uri' => $uri]);

        $client = new \GuzzleHttp\Client();
        $res = $client->request($method, $url, $guzzleParams);
        $resultData = json_decode($res->getBody(), true);
        $httpCode = $res->getStatusCode();

        $errors = [];
        if (isset($resultData['errors'])) {
            foreach ($resultData['errors'] as $error) {
                $errors[] = $error['message'];
            }
        }
        if ($httpCode && ($httpCode >= 300)) {
            $errors[] = "Http status code: $httpCode";
        }

        if (!empty($errors)) {
            throw new TerminusException('Error: {message} {errors}', ['errors' => implode("\n", $errors)]);
        }

        return $resultData;
    }

    /**
     * @inheritdoc
     */
    function branchesForPullRequests($target_project, $state)
    {
        $stateParameters = [
            'open' => ['opened'],
            'closed' => ['closed'],
            'all' => ['all']
        ];

        if (!isset($stateParameters[$state]))
            throw new TerminusException("branchesForPullRequests - state must be one of: open, closed, all");

        $data = $this->gitLabAPI("projects/$target_project/merge_requests?state=" . implode('', $stateParameters[$state]));
        var_dump($data);
        $branchList = array_column(array_map(
            function ($item) {
                $pr_number = $item['number'];
                $branch_name = $item['head']['ref'];
                return [$pr_number, $branch_name];
            },
            $data
        ), 1, 0);

        return $branchList;
    }

    protected function execGit($dir, $cmd, $replacements = [], $redacted = [])
    {
        return $this->execWithRedaction('git {dir}' . $cmd, ['dir' => "-C $dir "] + $replacements, ['dir' => ''] + $redacted);
    }
}
