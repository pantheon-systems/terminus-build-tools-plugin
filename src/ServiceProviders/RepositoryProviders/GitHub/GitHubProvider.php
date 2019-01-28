<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\GitHub;

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

/**
 * Holds state information destined to be registered with the CI service.
 */
class GitHubProvider implements GitProvider, LoggerAwareInterface, CredentialClientInterface
{
    use LoggerAwareTrait;
    use ExecWithRedactionTrait;

    const SERVICE_NAME = 'github';
    const GITHUB_URL = 'https://github.com';
    const GITHUB_TOKEN = 'GITHUB_TOKEN';

    protected $repositoryEnvironment;

    public function __construct()
    {
    }

    public function infer($url)
    {
        return strpos($url, 'github.com') !== false;
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
        return self::GITHUB_TOKEN;
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
        $userData = $this->gitHubAPI('user');
        return $userData['login'];
    }

    /**
     * @inheritdoc
     */
    public function createRepository($local_site_path, $target, $github_org = '')
    {
        // We need a different URL here if $github_org is an org; if no
        // org is provided, then we use a simpler URL to create a repository
        // owned by the currently-authenitcated user.
        $createRepoUrl = "orgs/$github_org/repos";
        $target_org = $github_org;
        if (empty($github_org)) {
            $createRepoUrl = 'user/repos';
            $userData = $this->gitHubAPI('user');
            $target_org = $this->authenticatedUser();
        }
        $target_project = "$target_org/$target";

        // Create a GitHub repository
        $this->logger->notice('Creating repository {repo}', ['repo' => $target_project]);
        $postData = ['name' => $target];
        $result = $this->gitHubAPI($createRepoUrl, $postData);

        // Create a git repository. Add an origin just to have the data there
        // when collecting the build metadata later. We use the 'pantheon'
        // remote when pushing.
        // TODO: Do we need to remove $local_site_path/.git? (-n in create-project should obviate this need) We preserve this here because it may be user-provided via --preserve-local-repository
        if (!is_dir("$local_site_path/.git")) {
            $this->execGit($local_site_path, 'init');
        }
        // TODO: maybe in the future we will not need to set this?
        $this->execGit($local_site_path, "remote add origin 'git@github.com:{$target_project}.git'");

        return $target_project;
    }

    /**
     * @inheritdoc
     */
    public function pushRepository($dir, $target_project)
    {
        $this->execGit($dir, 'push --progress https://{token}:x-oauth-basic@github.com/{target}.git master', ['token' => $this->token(), 'target' => $target_project], ['token']);
    }

    /**
     * Convert a nested array into a list of GitHubRepositoryInfo object.s
     */
    protected function createRepositoryInfo($repoList)
    {
        $result = [];
        foreach ($repoList as $repo) {
            $repoInfo = new GitHubRepositoryInfo($repo);
            $result[$repoInfo->project()] = $repoInfo;
        }
        return $result;
    }

    /**
     * @inheritdoc
     */
    public function deleteRepository($project)
    {
        $deleteRepoUrl = "repos/$project";
        $this->gitHubAPI($deleteRepoUrl, [], 'DELETE');
    }

    /**
     * @inheritdoc
     */
    public function projectURL($target_project)
    {
        return self::GITHUB_URL . '/' . $target_project;
    }

    /**
     * @inheritdoc
     */
    public function commentOnCommit($target_project, $commit_hash, $message)
    {
        $url = "repos/$target_project/commits/$commit_hash/comments";
        $data = [ 'body' => $message ];
        $this->gitHubAPI($url, $data);
    }

    /**
     * @inheritdoc
     */
     function branchesForPullRequests($target_project, $state)
     {
        if (!in_array($state, ['open', 'closed', 'all']))
            throw new TerminusException("branchesForPullRequests - state must be one of: open, closed, all");

        $data = $this->gitHubAPI("repos/$target_project/pulls?state=$state");
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

    protected function gitHubAPI($uri, $data = [], $method = 'GET')
    {
        $url = "https://api.github.com/$uri";

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => ProviderEnvironment::USER_AGENT,
        ];

        if ($this->hasToken()) {
            $headers['Authorization'] = "token " . $this->token();;
        }

        $guzzleParams = [
            'headers' => $headers,
        ];
        if (!empty($data) && ($method == 'GET')) {
            $method = 'POST';
            $guzzleParams['json'] = $data;
        }

        $this->logger->notice('Call GitHub API: {method} {uri}', ['method' => $method, 'uri' => $uri]);

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

        $message = isset($resultData['message']) ? "{$resultData['message']}." : '';

        if (!empty($message) || !empty($errors)) {
            throw new TerminusException('{service} error: {message} {errors}', ['service' => $service, 'message' => $message, 'errors' => implode("\n", $errors)]);
        }

        return $resultData;
    }

    protected function execGit($dir, $cmd, $replacements = [], $redacted = [])
    {
        return $this->execWithRedaction('git {dir}' . $cmd, ['dir' => "-C $dir "] + $replacements, ['dir' => ''] + $redacted);
    }
}
