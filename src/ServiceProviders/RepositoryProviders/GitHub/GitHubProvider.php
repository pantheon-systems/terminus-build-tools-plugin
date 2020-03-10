<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\GitHub;

use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\BaseGitProvider;
use Psr\Log\LoggerAwareInterface;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusBuildTools\Credentials\CredentialClientInterface;
use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\GitProvider;
use Pantheon\TerminusBuildTools\API\GitHub\GitHubAPITrait;
use Pantheon\TerminusBuildTools\API\PullRequestInfo;

/**
 * Holds state information destined to be registered with the CI service.
 */
class GitHubProvider extends BaseGitProvider implements GitProvider, LoggerAwareInterface, CredentialClientInterface
{
    use GitHubAPITrait;

    protected $serviceName = 'github';
    protected $baseGitUrl = 'git@github.com';
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
    public function createRepository($local_site_path, $target, $github_org = '', $visibility = 'public')
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

        // Add parameter to the post request if repository was requested to be
        // private
        if($visibility != 'public') {
            $postData['private'] = 'true';
        }
        $result = $this->api()->request($createRepoUrl, $postData);

        // Create a git repository. Add an origin just to have the data there
        // when collecting the build metadata later. We use the 'pantheon'
        // remote when pushing.
        // TODO: Do we need to remove $local_site_path/.git? (-n in create-project should obviate this need) We preserve this here because it may be user-provided via --preserve-local-repository
        if (!is_dir("$local_site_path/.git")) {
            $this->execGit($local_site_path, 'init');
        }
        $this->execGit($local_site_path, "remote add origin '{$this->getBaseGitUrl()}:{$target_project}.git'");

        return $target_project;
    }

    /**
     * @inheritdoc
     */
    public function pushRepository($dir, $target_project, $use_ssh = false)
    {
        if ($use_ssh){
            $this->execGit($dir, 'push --progress origin master');
        } else {
            $this->execGit($dir, 'push --progress https://{token}:x-oauth-basic@github.com/{target}.git master', ['token' => $this->token(), 'target' => $target_project], ['token']);
        }
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
    public function commentOnPullRequest($target_project, $pr_id, $message)
    {
        $url = "repos/$target_project/issues/$pr_id/comments";
        $data = [ 'body' => $message ];
        $this->api()->request($url, $data);
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
    public function branchesForPullRequests($target_project, $state, $callback = null, $return_key = null)
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

    /**
     * GitHub returns 1 (not 0) for a successful connection.
     *
     * @return boolean
     */
    public function verifySSHConnect(){
        passthru(sprintf('ssh -T %s', $this->baseGitUrl), $result);
        return $result === 1;
    }
}
