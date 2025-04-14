<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CircleCI;

use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\BaseCIProvider;
use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CIProvider;
use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CIState;

use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;
use Pantheon\TerminusBuildTools\Task\Ssh\PrivateKeyReciever;
use Psr\Log\LoggerAwareInterface;

use Pantheon\TerminusBuildTools\Credentials\CredentialClientInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialProviderInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialRequest;

/**
 * Manages the configuration of a project to be tested on Circle CI.
 */
class CircleCIProvider extends BaseCIProvider implements CIProvider, LoggerAwareInterface, PrivateKeyReciever, CredentialClientInterface
{

    const CIRCLE_TOKEN = 'CIRCLE_TOKEN';

    protected $circle_token;
    protected $serviceName = 'circleci';

    public function infer($url)
    {
        return strpos($url, 'circleci.com') !== false;
    }

    /**
     * Return 'true' if our token has been set yet.
     */
    public function hasToken()
    {
        return isset($this->circle_token);
    }

    /**
     * Set our token. This will be called via 'setCredentials()', which is
     * called by the provider manager.
     */
    public function setToken($circle_token)
    {
        $this->circle_token = $circle_token;
    }

    public function token()
    {
        return $this->circle_token;
    }

    /**
     * @inheritdoc
     */
    public function credentialRequests()
    {
        // Tell the credential manager that we require one credential: the
        // CIRCLE_TOKEN that will be used to authenticate with the CircleCI server.
        $circleTokenRequest = new CredentialRequest(
            self::CIRCLE_TOKEN,
            "Please generate a Circle CI personal API token by visiting the page:\n\n    https://circleci.com/account/api\n\n For more information, see:\n\n    https://circleci.com/docs/api/v1-reference/#getting-started.",
            "Enter Circle CI personal API token: ",
            '#^[0-9a-zA-Z_]{70}$#',
            'Circle CI authentication tokens should be 40-character strings containing only the letters a-f and digits (0-9). Please enter your token again.'
        );

        $could_not_authorize = 'Your provided authentication token could not be used to authenticate with the CircleCI service. Please re-enter your credential.';

        $circleTokenRequest
            ->setValidationCallbackErrorMessage($could_not_authorize)
            ->setValidateFn(
                function ($token) {
                    $this->setToken($token);
                    $url = "https://circleci.com/api/v1.1/me";
                    $httpStatus = $this->circleCIAPI([], $url, 'GET');

                    return ($httpStatus == 200);
                }
            );

        return [ $circleTokenRequest ];
    }

    /**
     * @inheritdoc
     */
    public function setCredentials(CredentialProviderInterface $credentials_provider)
    {
        // Since the `credentialRequests()` method declared that we need a
        // CIRCLE_TOKEN credential, it will be available for us to copy from
        // the credentials provider when this method is called.
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
        $this->onlyBuildPullRequests($ci_env);
    }

    protected function onlyBuildPullRequests($ci_env)
    {
        $circle_url = $this->apiUrl($ci_env);
        $data = ['feature_flags' => ['build-prs-only' => true]];
        $this->circleCIAPI($data, "$circle_url/settings", 'PUT');
    }

    protected function setCircleEnvironmentVars(CIState $ci_env)
    {
        $circle_url = $this->apiUrl($ci_env);
        $env = $ci_env->getAggregateState();
        foreach ($env as $key => $value) {
            $data = ['name' => $key, 'value' => $value];
            if (empty($value)) {
                $this->logger->warning('Variable {key} empty: skipping.', ['key' => $key]);
            } else {
                $this->circleCIAPI($data, "$circle_url/envvar");
            }
        }
    }

    public function startTesting(CIState $ci_env)
    {
        // Wait for 5 seconds to ensure the branch has reached CircleCI so that follow action work as expected.
        sleep(5);
        $circle_url = $this->apiUrl($ci_env);
        $this->circleCIAPI([], "$circle_url/follow");
    }

    public function addPrivateKey(CIState $ci_env, $privateKey)
    {
        $circle_url = $this->apiUrl($ci_env);
        $privateKeyContents = file_get_contents($privateKey);
        $data = [
            'hostname' => 'drush.in',
            'private_key' => $privateKeyContents,
        ];
        $this->circleCIAPI($data, "$circle_url/ssh-key");
    }

    protected function circleCIAPI($data, $url, $method = 'POST')
    {
        $this->logger->notice('Call CircleCI API: {uri}', ['uri' => $url]);

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => ProviderEnvironment::USER_AGENT,
            'Accept' => 'application/json',
        ];

        $client = new \GuzzleHttp\Client();
        $request = [
            'headers' => $headers,
            'auth' => [$this->circle_token, ''],
        ];
        if ($method !== 'GET') {
            $request['json'] = $data;
        }
        $res = $client->request($method, $url, $request);
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
            'bitbucket' => 'bb',
        ];

        if (isset($serviceMap[$serviceName])) {
            return $serviceMap[$serviceName];
        }
        return $serviceName;
    }
}
