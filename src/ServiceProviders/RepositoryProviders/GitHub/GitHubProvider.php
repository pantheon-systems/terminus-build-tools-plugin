<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\GitHub;

use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;
use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\BaseGitProvider;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusBuildTools\Credentials\CredentialClientInterface;
use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\GitProvider;
use Pantheon\TerminusBuildTools\Credentials\CredentialRequest;
use Pantheon\TerminusBuildTools\Utility\ExecWithRedactionTrait;
use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\RepositoryEnvironment;
use Pantheon\TerminusBuildTools\API\GitHub\GitHubAPI;
use Pantheon\TerminusBuildTools\API\GitHub\GitHubAPITrait;
use Pantheon\TerminusBuildTools\API\PullRequestInfo;
use Robo\Config\Config;

/**
 * Holds state information destined to be registered with the CI service.
 */
class GitHubProvider extends BaseGitProvider implements GitProvider, LoggerAwareInterface, CredentialClientInterface
{
    use GitHubAPITrait;

    protected $serviceName = 'github';
    const GITHUB_URL = 'https://github.com';

    /** @var WebAPIInterface */
    protected $api;


    public function infer($url)
    {
        return strpos($url, 'github.com') !== false;
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
            $userData = $this->api()->request('user');
            $target_org = $this->authenticatedUser();
        }
        $target_project = "$target_org/$target";

        // Create a GitHub repository
        $this->logger->notice('Creating repository {repo}', ['repo' => $target_project]);
        $postData = ['name' => $target];
        $result = $this->api()->request($createRepoUrl, $postData);

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
     * @inheritdoc
     */
    public function deleteRepository($project)
    {
        $deleteRepoUrl = "repos/$project";
        $this->api()->request($deleteRepoUrl, [], 'DELETE');
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
        $this->api()->request($url, $data);
    }

    /**
     * @inheritdoc
     */
    public function branchesForPullRequests($target_project, $state, $callback = null)
    {
        if (!in_array($state, ['open', 'closed', 'all']))
            throw new TerminusException("branchesForPullRequests - state must be one of: open, closed, all");

        $data = $this->api()->pagedRequest("repos/$target_project/pulls", $callback, ['state' => $state]);
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

    public function convertPRInfo($data)
    {
        $isClosed = ($data['state'] == 'closed');
        return new PullRequestInfo($data['number'], $isClosed, $data['head']['ref']);
    }
}
