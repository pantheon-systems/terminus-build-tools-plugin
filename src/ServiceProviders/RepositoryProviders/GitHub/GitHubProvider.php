<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\GitHub;

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

        $data = $this->gitHubAPI("repos/$target_project/pulls?state=$state", [], 'GET', TRUE);
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

    protected function gitHubAPI($uri, $data = [], $method = 'GET', $follow_next_link = FALSE)
    {
        $url = "https://api.github.com/$uri";

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'pantheon/terminus-build-tools-plugin'
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
            throw new TerminusException('error: {message} {errors}', ['message' => $message, 'errors' => implode("\n", $errors)]);
        }

        // The request may be against a paged collection. If that is the case, traverse the "next" links sequentially
        // (since it's simpler and PHP doesn't have non-blocking I/O) until the end and accumulate the results.
        $headers = $res->getHeaders();
        // Check if the array is numeric. Otherwise we can't consider this a collection. Ideally GitHub would tell us
        // that the response is a collection but we'll need to guess from the response format.
        if ($follow_next_link && $this->isSequentialArray($resultData) && $this->isPagedResponse($headers)) {
            $pager_info = $this->getPagerInfo($headers['Link']);
            if (!$this->isLastPage($uri, $pager_info)) {
                $next_page_uri = $this->getNextPageUri($pager_info);
                var_dump($next_page_uri);
                // Request the next page and append the data.
                $resultData = array_merge_recursive(
                    $resultData,
                    $this->gitHubAPI($next_page_uri, $data, $method, TRUE)
                );
            }
        }
        return $resultData;
    }

    protected function isSequentialArray($input)
    {
        if (!is_array($input)) {
            return FALSE;
        }
        if (empty($input)) {
            return TRUE;
        }
        $keys = array_keys($input);
        $keys_of_keys = array_keys($keys);
        for ($i = 0; $i < count($keys); $i++) {
            if ($keys[$i] !== $keys_of_keys[$i]) {
                return FALSE;
            }
        }
        return TRUE;
    }

    protected function isPagedResponse($headers)
    {
        if (empty($headers['Link'])) {
            return FALSE;
        }
        $links = $headers['Link'];
        // Find a link header that contains a "rel" type set to "next" or "last".
        $pager_headers = array_filter($links, function ($link) {
            return strpos($link, 'rel="next"') !== FALSE || strpos($link, 'rel="last"') !== FALSE;
        });
        return !empty($pager_headers);
    }

    protected function getPagerInfo($links)
    {
        // Find a link header that contains a "rel" type set to "next" or "last".
        $pager_headers = array_filter($links, function ($link) {
            return strpos($link, 'rel="next"') !== FALSE || strpos($link, 'rel="last"') !== FALSE;
        });
        // There is only one possible link header.
        $pager_header = reset($pager_headers);
        // $pager_header looks like '<https://…>; rel="next", <https://…>; rel="last"'
        $pager_parts = array_map('trim', explode(',', $pager_header));
        $parse_link_pager_part = function ($link_pager_part) {
            // $link_pager_part is '<href>; key1="value1"; key2="value2"'
            $sub_parts = array_map('trim', explode(';', $link_pager_part));

            $href = array_shift($sub_parts);
            $href = preg_replace('@^https:\/\/api.github.com\/@', '', trim($href, '<>'));
            $parsed = ['href' => $href];
            return array_reduce($sub_parts, function ($carry, $sub_part) {
                list($key, $value) = explode('=', $sub_part);
                if (empty($key) || empty($value)) {
                    return $carry;
                }
                return array_merge($carry, [$key => trim($value, '"')]);
            }, $parsed);
        };
        return array_map($parse_link_pager_part, $pager_parts);
    }

    protected function isLastPage($page_link, $pager_info)
    {
        $res = array_filter($pager_info, function ($item) {
            return isset($item['rel']) && $item['rel'] === 'last';
        });
        $last_item = reset($res);
        return isset($next_item['href']) ? $last_item['href'] === $page_link : FALSE;
    }

    protected function getNextPageUri($pager_info)
    {
        $res = array_filter($pager_info, function ($item) {
            return isset($item['rel']) && $item['rel'] === 'next';
        });
        $next_item = reset($res);
        return isset($next_item['href']) ? $next_item['href'] : NULL;
    }

    protected function execGit($dir, $cmd, $replacements = [], $redacted = [])
    {
        return $this->execWithRedaction('git {dir}' . $cmd, ['dir' => "-C $dir "] + $replacements, ['dir' => ''] + $redacted);
    }
}
