<?php

namespace Pantheon\TerminusBuildTools\API\Bitbucket;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusBuildTools\API\WebAPI;
use Pantheon\TerminusBuildTools\API\WebAPIInterface;
use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;
use Pantheon\TerminusBuildTools\ServiceProviders\ServiceTokenStorage;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * BitbucketAPI manages calls to the Bitbucket API.
 */
class BitbucketAPI extends WebAPI
{
    const SERVICE_NAME = 'bitbucket';
    const BITBUCKET_USER = 'BITBUCKET_USER';
    const BITBUCKET_PASS = 'BITBUCKET_PASS';
    const BITBUCKET_AUTH = 'BITBUCKET_AUTH';
    const BITBUCKET_TOKEN = 'BITBUCKET_TOKEN';
    const BITBUCKET_TOKEN_EXPIRATION = 'BITBUCKET_TOKEN_EXPIRATION';

    public function serviceHumanReadableName()
    {
        return 'Bitbucket';
    }

    public function serviceName()
    {
        return self::SERVICE_NAME;
    }

    protected function apiClient()
    {
        $headers = [
            'User-Agent' => ProviderEnvironment::USER_AGENT,
        ];

        // If we have an auth token, instantiate OAuth client.
        if ($auth_token = $this->getAuthToken()) {
            $headers['Authorization'] = 'Bearer ' . $auth_token;
            return new \GuzzleHttp\Client(
                [
                    'base_uri' => 'https://api.bitbucket.org/2.0/',
                    'headers' => $headers,
                ]
            );
        }

      // Otherwise, instantiate the basic auth client.
      return new \GuzzleHttp\Client(
            [
                'base_uri' => 'https://api.bitbucket.org/2.0/',
                'auth' => [ $this->serviceTokenStorage->token(self::BITBUCKET_USER), $this->serviceTokenStorage->token(self::BITBUCKET_PASS) ],
                'headers' => $headers,
            ]
        );
    }

    protected function getAuthToken() {
        if (!$this->serviceTokenStorage->hasToken(self::BITBUCKET_USER)
        || !$this->serviceTokenStorage->hasToken(self::BITBUCKET_PASS)) {
            return FALSE;
        }

        if ($this->serviceTokenStorage->hasToken(self::BITBUCKET_TOKEN)
        && $this->serviceTokenStorage->hasToken(self::BITBUCKET_TOKEN_EXPIRATION)
        && $this->serviceTokenStorage->hasToken(self::BITBUCKET_TOKEN_EXPIRATION) > time()) {
            return $this->serviceTokenStorage->token(self::BITBUCKET_TOKEN);
        }

        // Post a request for new auth token.
        $client = new \GuzzleHttp\Client();
        $authResponse = $client->request('POST', 'https://bitbucket.org/site/oauth2/access_token',
            [
                'auth' => [$this->serviceTokenStorage->token(self::BITBUCKET_USER),  $this->serviceTokenStorage->token(self::BITBUCKET_PASS)],
              'form_params' => ['grant_type' => 'client_credentials']
            ]
        );
        if ($authResponse->getStatusCode() == '200') {
            $response = json_decode($authResponse->getBody()->getContents());
            $this->serviceTokenStorage->setToken(self::BITBUCKET_TOKEN, $response->access_token);
            $this->serviceTokenStorage->setToken(self::BITBUCKET_TOKEN_EXPIRATION, time() + (int)$response->expires_in);
            return $response->access_token;
        }
        return FALSE;
    }

    protected function isPagedResponse($headers)
    {
        return true;
    }

    protected function getPagerInfo($links)
    {
        return [];
    }

    protected function isLastPage($page_link, $pager_info)
    {
        return true;
    }

    protected function getNextPageUri($pager_info)
    {
        return null;
    }
}
