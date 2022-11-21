<?php

namespace Pantheon\TerminusBuildTools\API\Bitbucket;

use Pantheon\TerminusBuildTools\API\WebAPI;
use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;
use Psr\Http\Message\ResponseInterface;
use Robo\Config\Config;

/**
 * BitbucketAPI manages calls to the Bitbucket API.
 */
class BitbucketAPI extends WebAPI
{
    const SERVICE_NAME = 'bitbucket';
    const BITBUCKET_USER = 'BITBUCKET_USER';
    const BITBUCKET_PASS = 'BITBUCKET_PASS';
    const BITBUCKET_AUTH = 'BITBUCKET_AUTH';
    const BITBUCKET_CONFIG_PATH = 'build-tools.provider.git.bitbucket.url';
    const BITBUCKET_URL_DEFAULT = 'bitbucket.org';

    private $BITBUCKET_URL;

    public static function determineBitbucketUrl(Config $config)
    {
        // Robo's Config object in combination with Terminus does not properly expand
        // environment variable configurations for nested items. This temporary env
        // detection can be removed once resolved. This can be ovserved by using
        // `terminus self:config:dump` from a local environment with the configuration
        // set via config.yml and from a CI environment with the below env variable set.
        if ($preservedBitbucketUrl = getenv('TERMINUS_BUILD_TOOLS_PROVIDER_GIT_BITBUCKET_URL')) {
            return $preservedBitbucketUrl;
        }
        return $config->get(self::BITBUCKET_CONFIG_PATH, self::BITBUCKET_URL_DEFAULT);
    }

    public function getBitbucketUrl()
    {
        return $this->BITBUCKET_URL;
    }

    public function setBitbucketUrl($BITBUCKET_URL)
    {
        $this->BITBUCKET_URL = $BITBUCKET_URL;
    }

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
                // @todo: Is this URL right for hosted bitbucket?
                'base_uri' => 'https://api.' . $this->getBitbucketUrl() . '/2.0/',
                // @todo: Is this auth mechanism right for hosted bitbucket?
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
