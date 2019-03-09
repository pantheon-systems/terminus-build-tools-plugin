<?php

namespace Pantheon\TerminusBuildTools\API\Bitbucket;

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

        return new \GuzzleHttp\Client(
            [
                'base_uri' => 'https://api.bitbucket.org/2.0/',
                'auth' => [ $this->serviceTokenStorage->token(self::BITBUCKET_USER), $this->serviceTokenStorage->token(self::BITBUCKET_PASS) ],
                'headers' => $headers,
            ]
        );
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
