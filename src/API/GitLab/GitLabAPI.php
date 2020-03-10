<?php

namespace Pantheon\TerminusBuildTools\API\GitLab;

use Pantheon\TerminusBuildTools\API\WebAPI;
use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;
use Psr\Http\Message\ResponseInterface;
use Robo\Config\Config;

/**
 * GitLabAPI manages calls to the GitLab API.
 */
class GitLabAPI extends WebAPI
{
    const SERVICE_NAME = 'gitlab';
    const GITLAB_TOKEN = 'GITLAB_TOKEN';
    const GITLAB_CONFIG_PATH = 'build-tools.provider.git.gitlab.url';
    const GITLAB_URL_DEFAULT = 'gitlab.com';

    private $GITLAB_URL;

    public function serviceHumanReadableName()
    {
        return 'GitLab';
    }

    public function serviceName()
    {
        return self::SERVICE_NAME;
    }

    protected function apiClient()
    {
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => ProviderEnvironment::USER_AGENT,
        ];

        if ($this->serviceTokenStorage->hasToken(self::GITLAB_TOKEN)) {
            $headers['PRIVATE-TOKEN'] = $this->serviceTokenStorage->token(self::GITLAB_TOKEN);
        }

        return new \GuzzleHttp\Client(
            [
                'base_uri' => 'https://' . $this->getGitLabUrl(),
                'headers' => $headers,
            ]
        );
    }

    public static function determineGitLabUrl(Config $config)
    {
        // Robo's Config object in combination with Terminus does not properly expand
        // environment variable configurations for nested items. This temporary env
        // detection can be removed once resolved. This can be ovserved by using
        // `terminus self:config:dump` from a local environment with the configuration
        // set via config.yml and from a CI environment with the below env variable set.
        if ($preservedGitLabUrl = getenv('TERMINUS_BUILD_TOOLS_PROVIDER_GIT_GITLAB_URL'))
        {
            return $preservedGitLabUrl;
        }
        return $config->get(self::GITLAB_CONFIG_PATH, self::GITLAB_URL_DEFAULT);
    }

    public function getGitLabUrl()
    {
        return $this->GITLAB_URL;
    }

    public function setGitLabUrl($gitlab_url)
    {
        $this->GITLAB_URL = $gitlab_url;
    }

    protected function isPagedResponse(ResponseInterface $res)
    {
        $headers = $res->getHeaders();
        if (empty($headers['Link'])) {
          return FALSE;
        }
        $links = $headers['Link'];
        // Find a link header that contains a "rel" type set to "next" or "last".
        $pager_headers = array_filter($links, function ($link) {
            return strpos($link, 'rel="next"') !== FALSE || strpos($link, 'rel="last"') !== FALSE;
        });
        return !empty($pager_headers);
    }

    protected function getPagerInfo(ResponseInterface $res)
    {
        $headers = $res->getHeaders();
        $links = $headers['Link'];
        // Find a link header that contains a "rel" type set to "next" or "last".
        $pager_headers = array_filter($links, function ($link) {
            return strpos($link, 'rel="next"') !== FALSE || strpos($link, 'rel="last"') !== FALSE;
        });
        // There is only one possible link header.
        $pager_header = reset($pager_headers);
        // $pager_header looks like '<https://…>; rel="next", <https://…>; rel="last"'
        $pager_parts = array_map('trim', explode(',', $pager_header));
        $parse_link_pager_part = function ($link_pager_part) {
            // $link_pager_part is '<href>; key1="value1"; key2="value2"'
            $sub_parts = array_map('trim', explode(';', $link_pager_part));

            $href = array_shift($sub_parts);
            $href = preg_replace('@^https:\/\/' . $this->getGitLabUrl() . '\/@', '', trim($href, '<>'));
            $parsed = ['href' => $href];
            return array_reduce($sub_parts, function ($carry, $sub_part) {
                list($key, $value) = explode('=', $sub_part);
                if (empty($key) || empty($value)) {
                    return $carry;
                }
                return array_merge($carry, [$key => trim($value, '"')]);
            }, $parsed);
        };
        return array_map($parse_link_pager_part, $pager_parts);
    }

    protected function isLastPage($page_link, $pager_info)
    {
        $res = array_filter($pager_info, function ($item) {
            return isset($item['rel']) && $item['rel'] === 'last';
        });
        $last_item = reset($res);

        return (isset($last_item) ? $last_item['href'] === $page_link : FALSE) || is_null($this->getNextPageUri($pager_info));
    }

    protected function getNextPageUri($pager_info)
    {
        $res = array_filter($pager_info, function ($item) {
            return isset($item['rel']) && $item['rel'] === 'next';
        });
        $next_item = reset($res);
        return isset($next_item['href']) ? $next_item['href'] : NULL;
    }
}
