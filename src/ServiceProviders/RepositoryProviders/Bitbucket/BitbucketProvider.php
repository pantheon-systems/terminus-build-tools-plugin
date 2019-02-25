<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\Bitbucket;

use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

use Pantheon\TerminusBuildTools\Credentials\CredentialClientInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialProviderInterface;
use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\GitProvider;
use Pantheon\TerminusBuildTools\Credentials\CredentialRequest;
use Pantheon\TerminusBuildTools\Utility\ExecWithRedactionTrait;
use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\RepositoryEnvironment;

use GuzzleHttp\Client;

/**
 * Encapsulates access to Bitbucket through git and the Bitbucket API.
 */
class BitbucketProvider implements GitProvider, LoggerAwareInterface, CredentialClientInterface
{
    use LoggerAwareTrait;
    use ExecWithRedactionTrait;

    const SERVICE_NAME = 'bitbucket';
    const BITBUCKET_URL = 'https://bitbucket.org';
    const BITBUCKET_USER = 'BITBUCKET_USER';
    const BITBUCKET_PASS = 'BITBUCKET_PASS';
    const BITBUCKET_AUTH = 'BITBUCKET_AUTH';

    private $bitbucketClient;
    protected $repositoryEnvironment;
    protected $bitBucketUser;
    protected $bitBucketPassword;

    public function __construct()
    {
    }

    public function infer($url)
    {
        return strpos($url, 'bitbucket.org') !== false;
    }

    public function getEnvironment()
    {
        if (!$this->repositoryEnvironment) {
            $this->repositoryEnvironment = (new RepositoryEnvironment())
            ->setServiceName(self::SERVICE_NAME);
        }
        return $this->repositoryEnvironment;
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
        $repositoryEnvironment->setToken(self::BITBUCKET_AUTH, $token);
    }

    public function getBitBucketUser()
    {
        return $this->bitBucketUser;
    }

    public function getBitBucketPassword()
    {
        return $this->bitBucketPassword;
    }

    public function setBitBucketUser($u)
    {
        $this->bitBucketUser = $u;
    }

    public function setBitBucketPassword($pw)
    {
        $this->bitBucketPassword = $pw;
    }

    /**
     * @inheritdoc
     */
    public function credentialRequests()
    {
        // Tell the credential manager that we require two credentials
        $bitbucketUserRequest = new CredentialRequest(
            self::BITBUCKET_USER,
            "",
            "Enter your Bitbucket username",
            '#^.+$#',
            ""
        );

        $bitbucketPassRequest = new CredentialRequest(
            self::BITBUCKET_PASS,
            "",
            "Enter your Bitbucket account password or an app password",
            '#^.+$#',
            ""
        );

        return [ $bitbucketUserRequest, $bitbucketPassRequest ];
    }

    /**
     * @inheritdoc
     */
    public function setCredentials(CredentialProviderInterface $credentials_provider)
    {
        // Since the `credentialRequests()` method declared that we need a
        // BITBUCKET_USER and BITBUCKET_PASS credentials, it will be available
        // for us to copy from the credentials provider when this method is called.
        $this->setBitBucketUser($credentials_provider->fetch(self::BITBUCKET_USER));
        $this->setBitBucketPassword($credentials_provider->fetch(self::BITBUCKET_PASS));
        $this->setToken(
            urlencode($this->getBitBucketUser())
            .':'.
            urlencode($this->getBitBucketPassword())
        );
    }

    /**
     * @inheritdoc
     */
    public function authenticatedUser()
    {
        return $this->getBitBucketUser();
    }

    /**
     * @inheritdoc
     */
    public function createRepository($local_site_path, $target, $org = '')
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
        $result = $this->bitbucketAPI('repositories/'.$target_project, 'PUT');

        // Create a git repository. Add an origin just to have the data there
        // when collecting the build metadata later. We use the 'pantheon'
        // remote when pushing.
        // TODO: Do we need to remove $local_site_path/.git? (-n in create-project should obviate this need) We preserve this here because it may be user-provided via --preserve-local-repository
        if (!is_dir("$local_site_path/.git")) {
            $this->execGit($local_site_path, 'init');
        }
        // TODO: maybe in the future we will not need to set this?
        $this->execGit($local_site_path, "remote add origin 'git@bitbucket.org:{$target_project}.git'");
        return $target_project;
    }

    /**
     * @inheritdoc
     */
    public function pushRepository($dir, $target_project)
    {
        $bitbucket_token = $this->token();
        $remote_url = "https://$bitbucket_token@bitbucket.org/${target_project}.git";
        $this->execGit($dir, 'push --progress {remote} master', ['remote' => $remote_url], ['remote' => $target_project]);
    }

    /**
     * @inheritdoc
     */
    public function deleteRepository($project)
    {
        // TODO:
    }

    /**
     * @inheritdoc
     */
    public function projectURL($target_project)
    {
        return self::BITBUCKET_URL . '/' . $target_project;
    }

    /**
     * @inheritdoc
     */
    public function commentOnCommit($target_project, $commit_hash, $message)
    {
        // We're using the 1.0 API here because this is not yet supported in 2.0
        $url = "/1.0/repositories/$target_project/changesets/$commit_hash/comments";
        $data = [ 'content' => $message ];
        $this->bitbucketAPIClient()->post($url, [
           'form_params' => $data
        ]);
    }

    /**
     * @inheritdoc
     */
    function branchesForPullRequests($target_project, $state)
    {
        $stateParameters = [
            'open' => ['OPEN'],
            'closed' => ['MERGED', 'DECLINED', 'SUPERSEDED'],
            'all' => ['MERGED', 'DECLINED', 'SUPERSEDED', 'OPEN']
        ];
        if (!isset($stateParameters[$state]))
            throw new TerminusException("branchesForPullRequests - state must be one of: open, closed, all");
        
        $data = $this->bitbucketAPI("repositories/$target_project/pullrequests?state="
            .implode('&state=', $stateParameters[$state]));

        $branchList = array_column(array_map(
            function ($item) {
                $pr_number = $item['id'];
                $branch_name = $item['source']['branch']['name'];
                return [$pr_number, $branch_name];
            },
            $data['values']
        ), 1, 0);

        return $branchList;
    }

    private function bitbucketAPIClient()
    {
        if (!isset($this->bitbucketClient))
            $this->bitbucketClient = new Client([
                'base_uri' => 'https://api.bitbucket.org/2.0/',
                'auth' => [ $this->getBitBucketUser(), $this->getBitBucketPassword() ],
                'headers' => [
                    'User-Agent' => ProviderEnvironment::USER_AGENT,
                ]
            ]);
        return $this->bitbucketClient;
    }

    protected function bitbucketAPI($uri, $method = 'GET', $data = [])
    {
        $guzzleParams = [];
        if (!empty($data)) {
            $guzzleParams['json'] = $data;
        }

        $this->logger->notice('Call Bitbucket API: {method} {uri}', ['method' => $method, 'uri' => $uri]);
        
        $res = $this->bitbucketAPIClient()->request($method, $uri, $guzzleParams);
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
