<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

use Pantheon\TerminusBuildTools\Credentials\CredentialClientInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialProviderInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialRequest;

/**
 * Holds state information destined to be registered with the CI service.
 */
class GithubProvider implements GitProvider, LoggerAwareInterface, CredentialClientInterface
{
    use LoggerAwareTrait;

    const SERVICE_NAME = 'github';
    const GITHUB_TOKEN = 'GITHUB_TOKEN';

    protected $repositoryEnvironment;

    public function __construct()
    {
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
        return $repositoryEnvironment->hasToken();
    }

    public function setToken($token)
    {
        $repositoryEnvironment = $this->getEnvironment();
        $repositoryEnvironment->setToken(self::GITHUB_TOKEN, $token);
    }

    /**
     * @inheritdoc
     */
    public function credentialRequests()
    {
        // Tell the credential manager that we require one credential: the
        // GITHUB_TOKEN that will be used to authenticate with the CircleCI server.
        $githubTokenRequest = new CredentialRequest(
            self::GITHUB_TOKEN,
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
        $this->setToken($credentials_provider->fetch(self::GITHUB_TOKEN));
    }

    protected function createRepository($local_site_path, $target, $github_org = '')
    {
        // We need a different URL here if $github_org is an org; if no
        // org is provided, then we use a simpler URL to create a repository
        // owned by the currently-authenitcated user.
        $createRepoUrl = "orgs/$github_org/repos";
        $target_org = $github_org;
        if (empty($github_org)) {
            $createRepoUrl = 'user/repos';
            $userData = $this->curlGitHub('user');
            $target_org = $userData['login'];
        }
        $target_project = "$target_org/$target";

        // Create a GitHub repository
        $this->log()->notice('Creating repository {repo}', ['repo' => $target_project]);
        $postData = ['name' => $target];
        $result = $this->curlGitHub($createRepoUrl, $postData);

        // Create a git repository. Add an origin just to have the data there
        // when collecting the build metadata later. We use the 'pantheon'
        // remote when pushing.
        // TODO: Do we need to remove $local_site_path/.git? (-n in create-project should obviate this need) We preserve this here because it may be user-provided via --preserve-local-repository
        if (!is_dir("$local_site_path/.git")) {
            $this->execGit($local_site_path, 'init');
        }
        $this->execGit($local_site_path, "remote add origin 'git@github.com:{$target_project}.git'");

        return $target_project;
    }

    protected function curlGitHub($uri, $data = [])
    {
        $this->logger->notice('Call GitHub API: {uri}', ['uri' => $uri]);

        $url = "https://api.github.com/$uri";

        $headers = [
            'Content-Type: application/json',
            'User-Agent: pantheon/terminus-build-tools-plugin'
        ];

        if ($this->hasToken()) {
            $headers[] = "Authorization: token " . $this->token();
        }

        $client = new \GuzzleHttp\Client();
        $res = $client->request('POST', $url, [
            'headers' => $headers,
            'form_params' => $data,
        ]);
        return $res->getStatusCode();
    }

    // TODO: Make an abstract base class to move this to
    protected function execGit($dir, $cmd)
    {
        $command = 'git -C ' . escapeshellarg($dir) . ' ' . $cmd;

        passthru($command, $result);
        if ($result != 0) {
            throw new \Exception("Command `$command` failed with exit code $result");
        }
    }
}
