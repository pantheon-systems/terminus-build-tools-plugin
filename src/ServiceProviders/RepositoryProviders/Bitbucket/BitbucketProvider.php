<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\Bitbucket;

use Pantheon\TerminusBuildTools\API\PullRequestInfo;
use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\BaseGitProvider;
use Psr\Log\LoggerAwareInterface;

use Pantheon\TerminusBuildTools\Credentials\CredentialClientInterface;
use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\GitProvider;
use Pantheon\TerminusBuildTools\API\Bitbucket\BitbucketAPITrait;
use Pantheon\Terminus\Exceptions\TerminusException;
use Robo\Config\Config;
use Pantheon\TerminusBuildTools\API\Bitbucket\BitbucketApi;

/**
 * Encapsulates access to Bitbucket through git and the Bitbucket API.
 */
class BitbucketProvider extends BaseGitProvider implements GitProvider, LoggerAwareInterface, CredentialClientInterface
{
    use BitbucketAPITrait;

    protected $serviceName = 'bitbucket';
    protected $baseGitUrl = '';
    protected $BITBUCKET_URL = '';

    private $bitbucketClient;

    public function __construct(Config $config) {
        parent::__construct($config);
        $this->setBitbucketUrl(BitbucketApi::determineBitbucketUrl($config));
        $this->baseGitUrl = 'git@' . $this->getBitbucketUrl();
    }

    public function infer($url) {
        return strpos($url, $this->getBitbucketUrl()) !== FALSE;
    }

    /**
     * @return string
     */
    public function getBitbucketUrl() {
        return $this->BITBUCKET_URL;
    }

    /**
     * @param string $BITBUCKET_URL
     */
    public function setBitbucketUrl($BITBUCKET_URL) {
        $this->BITBUCKET_URL = $BITBUCKET_URL;
    }

    public function generateBuildProvidersData($git_service_name, $ci_service_name)
    {
        $metadata = parent::generateBuildProvidersData($git_service_name, $ci_service_name);
        $metadata['api-host'] = $this->getBitbucketUrl();
        return $metadata;
    }

    /**
     * @inheritdoc
     */
    public function createRepository($local_site_path, $target, $org = '', $visibility = "public")
    {
        // repository id must be lower case.
        $target = strtolower($target);

        // Username for Bitbucket API is either provider $org
        // or username
        if (empty($org)) {
            $org = $this->getBitBucketUser();
        }
        $target_project = "$org/$target";

        // Create a Bitbucket repository
        $this->logger->notice('Creating repository {repo}', ['repo' => $target_project]);
        $postData = [];
        if ($visibility != 'public') {
          $postData['is_private'] = TRUE;
        }
        $result = $this->api()->request("repositories/$target_project", $postData, 'PUT');

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
        if ($use_ssh) {
            $this->execGit($dir, 'push --progress origin master');
        } else {
            $bitbucket_token = $this->token();
            $bitbucket_url = $this->getBitbucketUrl();
            $remote_url = "https://$bitbucket_token@$bitbucket_url/${target_project}.git";
            $this->execGit($dir, 'push --progress {remote} master', ['remote' => $remote_url], ['remote' => $target_project]);
        }
    }

    /**
     * @inheritdoc
     */
    public function deleteRepository($project)
    {
        $this->logger->notice('Deleting repository {repo}', ['repo' => $project]);
        $result = $this->api()->request("repositories/$project", [], 'DELETE');
    }

    /**
     * @inheritdoc
     */
    public function projectURL($target_project)
    {
        return 'https://' . $this->getBitbucketUrl() . '/' . $target_project;
    }

    /**
     * @inheritdoc
     */
    public function commentOnPullRequest($target_project, $pr_id, $message)
    {
        $body = [
            'content' => [
                'raw' => $message,
            ],
        ];
        $result = $this->api()->request("repositories/$target_project/pullrequests/$pr_id/comments", $body);
    }

    /**
     * @inheritdoc
     */
    public function commentOnCommit($target_project, $commit_hash, $message)
    {
        $body = [
            'content' => [
                'raw' => $message,
            ],
            // 'parent' => '???', // not sure if this is needed
        ];
        $result = $this->api()->request("repositories/$target_project/commit/$commit_hash/comments", $body);
    }

    /**
     * @inheritdoc
     */
    public function branchesForPullRequests($target_project, $state, $callback = null, $return_key = null)
    {
        $stateParameters = [
            'open' => ['OPEN'],
            'closed' => ['MERGED', 'DECLINED', 'SUPERSEDED'],
            'all' => ['MERGED', 'DECLINED', 'SUPERSEDED', 'OPEN']
        ];
        if (!isset($stateParameters[$state]))
            throw new TerminusException("branchesForPullRequests - state must be one of: open, closed, all");

        $data = $this->api()->pagedRequest("repositories/$target_project/pullrequests", $callback, ['state' => implode('&state=', $stateParameters[$state])]);

        $branchList = array_column(array_map(
            function ($item) {
                $pr_number = $item['id'];
                $branch_name = $item['source']['branch']['name'];
                return [$pr_number, $branch_name];
            },
            $data
        ), 1, 0);

        return $branchList;
    }

    public function convertPRInfo($data)
    {
        $isClosed = ($data['state'] != 'OPEN');
        return new PullRequestInfo($data['id'], $isClosed, $data['source']['branch']['name']);
    }

    public function getSecretValues() {
      return parent::getSecretValues() + [
        'user' => $this->getBitBucketUser(),
        'password' => $this->getBitBucketPassword(),
      ];
    }

}
