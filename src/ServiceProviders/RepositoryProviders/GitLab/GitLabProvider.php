<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\GitLab;

use Pantheon\TerminusBuildTools\API\GitLab\GitLabAPI;
use Pantheon\TerminusBuildTools\API\GitLab\GitLabAPITrait;
use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;
use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\BaseGitProvider;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusBuildTools\Credentials\CredentialClientInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialProviderInterface;
use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\GitProvider;
use Pantheon\TerminusBuildTools\Credentials\CredentialRequest;
use Pantheon\TerminusBuildTools\Utility\ExecWithRedactionTrait;
use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\RepositoryEnvironment;
use Pantheon\TerminusBuildTools\API\PullRequestInfo;
use Robo\Common\ConfigAwareTrait;
use Robo\Config\Config;

/**
 * Encapsulates access to GitLab through git and the GitLab API.
 */
class GitLabProvider extends BaseGitProvider implements GitProvider, LoggerAwareInterface, CredentialClientInterface {
    use GitLabAPITrait;

    protected $serviceName = 'gitlab';
    // We make this modifiable as individuals can self-host GitLab.
    protected $GITLAB_URL;
    const GITLAB_TOKEN = 'GITLAB_TOKEN';

    public function __construct(Config $config) {
        parent::__construct($config);
        $this->setGitLabUrl(GitLabAPI::determineGitLabUrl($config));
    }

    /**
     * @return array|mixed|null
     */
    public function getGitLabUrl() {
        return $this->GITLAB_URL;
    }

    /**
     * @param array|mixed|null $GITLAB_URL
     */
    public function setGitLabUrl($GITLAB_URL) {
        $this->GITLAB_URL = $GITLAB_URL;
    }

    public function infer($url) {
        return strpos($url, $this->getGitLabUrl()) !== FALSE;
    }

    /**
     * @inheritdoc
     */
    public function createRepository($local_site_path, $target, $gitlab_org = '') {
        $createRepoUrl = "api/v4/projects";
        $target_org = $gitlab_org;
        if (empty($gitlab_org)) {
            $target_org = $this->authenticatedUser();
            $postData = ['name' => $target];
        }
        else {
            // We need to look up the namespace ID.
            $group = $this->api()
                ->request('api/v4/groups/' . urlencode($gitlab_org));
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

        $result = $this->api()->request($createRepoUrl, $postData);

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
    public function pushRepository($dir, $target_project) {
        $this->execGit($dir, 'push --progress https://oauth2:{token}@{gitlab_url}/{target}.git master', [
            'token' => $this->token(),
            'gitlab_url' => $this->getGitLabUrl(),
            'target' => $target_project
        ], ['token']);
    }

    /**
     * @inheritdoc
     */
    public function deleteRepository($project) {
        $deleteRepoUrl = "api/v4/projects/" . urlencode($project);
        $this->api()->request($deleteRepoUrl, [], 'DELETE');
    }

    /**
     * @inheritdoc
     */
    public function projectURL($target_project) {
        return 'https://' . $this->getGitLabUrl() . '/' . $target_project;
    }

    /**
     * @inheritdoc
     */
    public function commentOnCommit($target_project, $commit_hash, $message) {
        // We need to check and see if a MR exists for this commit.
        $mrs = $this->api()
            ->request("api/v4/projects/" . urlencode($target_project) . "/merge_requests", ['state' => 'opened'], 'GET');
        $url = NULL;
        $data = [];
        foreach ($mrs as $mr) {
            if ($mr['sha'] == $commit_hash) {
                $url = "api/v4/projects/" . urlencode($target_project) . "/merge_requests/" . $mr['iid'] . "/notes";
                $data = ['body' => $message];
                break;
            }
        }
        if (is_null($url)) {
            $url = "api/v4/projects/" . urlencode($target_project) . "/repository/commits/" . $commit_hash . "/comments";
            $data = ['note' => $message];
        }

        $this->api()->request($url, $data);
    }

    public function getProjectID($target_project) {
        $project = $this->api()
            ->request("api/v4/projects/" . urlencode($target_project));

        if (empty($project)) {
            throw new TerminusException('Error: No GitLab project found for {target_project}', ['target_project' => $target_project]);
        }

        return $project['id'];
    }

    /**
     * @inheritdoc
     */
    function branchesForPullRequests($target_project, $state, $callback = NULL) {
        $stateParameters = [
            'open' => ['opened'],
            'closed' => ['merged', 'closed'],
            'all' => ['all']
        ];

        if (!isset($stateParameters[$state])) {
            throw new TerminusException("branchesForPullRequests - state must be one of: open, closed, all");
        }

        $projectID = $this->getProjectID($target_project);

        $data = $this->api()
            ->pagedRequest("api/v4/projects/$projectID/merge_requests", $callback, ['scope' => 'all', 'state' => implode('', $stateParameters[$state])]);
        $branchList = array_column(array_map(
            function ($item) {
                $pr_number = $item['iid'];
                $branch_name = $item['sha'];
                return [$pr_number, $branch_name];
            },
            $data
        ), 1, 0);

        return $branchList;
    }

    public function convertPRInfo($data) {
        $isClosed = in_array($data['state'], ['closed', 'merged']);
        return new PullRequestInfo($data['iid'], $isClosed, $data['sha']);
    }

    public function generateBuildProvidersData($git_service_name, $ci_service_name)
    {
        $metadata = parent::generateBuildProvidersData($git_service_name, $ci_service_name);
        $metadata['api-host'] = $this->getGitLabUrl();
        return $metadata;
    }

}
