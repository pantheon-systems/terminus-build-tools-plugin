<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\CIProviders;

use Pantheon\TerminusBuildTools\Task\Ssh\PrivateKeyReciever;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

use Pantheon\TerminusBuildTools\Credentials\CredentialClientInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialProviderInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialRequest;

/**
 * Holds state information destined to be registered with the CI service.
 */
class CircleCIProvider implements CIProvider, LoggerAwareInterface, PrivateKeyReciever, CredentialClientInterface
{
    use LoggerAwareTrait;

    const CIRCLE_TOKEN = 'CIRCLE_TOKEN';

    protected $circle_token;

    public function __construct()
    {
    }

    public function hasToken()
    {
        return isset($this->circle_token);
    }

    public function setToken($circle_token)
    {
        $this->circle_token = $circle_token;
    }

    /**
     * @inheritdoc
     */
    public function credentialRequests()
    {
        $circleTokenRequest = new CredentialRequest(
            self::CIRCLE_TOKEN,
            "Please generate a Circle CI personal API token by visiting the page:\n\n    https://circleci.com/account/api\n\n For more information, see:\n\n    https://circleci.com/docs/api/v1-reference/#getting-started.",
            "Enter Circle CI personal API token: ",
            '#^[0-9a-fA-F]{40}$#',
            'Circle CI authentication tokens should be 40-character strings containing only the letters a-f and digits (0-9). Please enter your token again.'
        );

        return [ $circleTokenRequest ];
    }

    /**
     * @inheritdoc
     */
    public function setCredentials(CredentialProviderInterface $credentials_provider)
    {
        $this->setToken($credentials_provider->fetch(self::CIRCLE_TOKEN));
    }

    public function projectUrl(CIState $ci_env)
    {
        $repositoryAttributes = $ci_env->getState('repository');
        $projectRepositoryType = $this->remapRepositoryServiceName($repositoryAttributes->serviceName());
        return "https://circleci.com/{$projectRepositoryType}/{$repositoryAttributes->projectId()}";
    }

    protected function apiUrl(CIState $ci_env)
    {
        $repositoryAttributes = $ci_env->getState('repository');
        $apiRepositoryType = $repositoryAttributes->serviceName();
        $target_project = $repositoryAttributes->projectId();

        return "https://circleci.com/api/v1.1/project/$apiRepositoryType/$target_project";
    }

    /**
     * Return the text for the badge for this CI service.
     */
    public function badge(CIState $ci_env)
    {
        $url = $this->projectUrl($ci_env);
        return "[![CircleCI]($url.svg?style=shield)]($url)";
    }

    /**
     * Write the CI environment variables to the Circle "envrionment variables" configuration section.
     *
     * @param CIState $ci_env
     * @param Session $session TEMPORARY to be removed
     */
    public function configureServer(CIState $ci_env)
    {
        $this->logger->notice('Configure Circle CI');
        $this->setCircleEnvironmentVars($ci_env);
    }

    protected function setCircleEnvironmentVars(CIState $ci_env)
    {
        $circle_url = $this->apiUrl($ci_env);
        $env = $ci_env->getAggregateState();
        foreach ($env as $key => $value) {
            $data = ['name' => $key, 'value' => $value];
            $this->curlCircleCI($data, "$circle_url/envvar");
        }
    }

    public function startTesting(CIState $ci_env)
    {
        $circle_url = $this->apiUrl($ci_env);
        $this->curlCircleCI([], "$circle_url/follow");
    }

    public function addPrivateKey(CIState $ci_env, $privateKey)
    {
        $circle_url = $this->apiUrl($ci_env);
        $privateKeyContents = file_get_contents($privateKey);
        $data = [
            'hostname' => 'drush.in',
            'private_key' => $privateKeyContents,
        ];
        $this->curlCircleCI($data, "$circle_url/ssh-key");
    }

    protected function curlCircleCI($data, $url)
    {
        $this->logger->notice('Call CircleCI API: {uri}', ['uri' => $url]);

        $client = new \GuzzleHttp\Client();
        $res = $client->request('POST', $url, [
            'auth' => [$this->circle_token, ''],
            'form_params' => $data,
        ]);
        return $res->getStatusCode();
    }

    /**
     * CircleCI uses abreviations for service names in project page URLs.
     * For example, 'github' is 'gh'. This conversion is NOT done in API URLs.
     */
    protected function remapRepositoryServiceName($serviceName)
    {
        $serviceMap = [
            'github' => 'gh',
        ];

        if (isset($serviceMap[$serviceName])) {
            return $serviceMap[$serviceName];
        }
        return $serviceName;
    }
}
