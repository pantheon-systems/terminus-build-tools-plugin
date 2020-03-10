<?php

namespace Pantheon\TerminusBuildTools\API\Bitbucket;

use Pantheon\TerminusBuildTools\API\WebAPI;
use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;
use Psr\Http\Message\ResponseInterface;

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

    protected function isPagedResponse(ResponseInterface $res)
    {
        $responseBody = json_decode($res->getBody(), true);
        if (!empty($responseBody['next']) || ! empty($responseBody['previous'])) {
            return true;
        }
        return false;
    }

    protected function getPagerInfo(ResponseInterface $res)
    {
        $responseBody = json_decode($res->getBody(), true);
        $pagerInfo = [];
        foreach (['next', 'previous'] as $type) {
            if (isset($responseBody[$type])) {
                $pagerInfo[$type] = $responseBody[$type];
            }
        }
        return $pagerInfo;
    }

    protected function isLastPage($page_link, $pager_info)
    {
        return empty($pager_info['next']);
    }

    protected function getNextPageUri($pager_info)
    {
        return !empty($pager_info['next']) ? $pager_info['next'] : NULL;
    }

    protected function getResultData(ResponseInterface $res)
    {
        $resultData = json_decode($res->getBody(), true);
        return isset( $resultData['values'] ) ? $resultData['values'] : [];
    }
}
