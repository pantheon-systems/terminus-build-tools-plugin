<?php

namespace Pantheon\TerminusBuildTools\API\GitHub;

use Pantheon\TerminusBuildTools\API\WebAPI;
use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;
use Psr\Http\Message\ResponseInterface;

/**
 * GitHubAPI manages calls to the GitHub API.
 */
class GitHubAPI extends WebAPI
{
    const SERVICE_NAME = 'github';
    const GITHUB_TOKEN = 'GITHUB_TOKEN';

    public function serviceHumanReadableName()
    {
        return 'GitHub';
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

        if ($this->serviceTokenStorage->hasToken(self::GITHUB_TOKEN)) {
            $headers['Authorization'] = "token " . $this->serviceTokenStorage->token(self::GITHUB_TOKEN);;
        }

        return new \GuzzleHttp\Client(
            [
                'base_uri' => 'https://api.github.com',
                'headers' => $headers,
            ]
        );
    }

    protected function alterPagedRequestQueryParams($queryParams)
    {
        // For debugging only: set the per-page down so the GitHub API pages sooner
        $per_page = getenv('TERMINUS_BUILD_TOOLS_REPO_PROVIDER_PER_PAGE');
        if ($per_page) {
            $queryParams['per_page'] = $per_page;
        }

        return $queryParams;
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
            $href = preg_replace('@^https:\/\/api.github.com\/@', '', trim($href, '<>'));
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

        return isset($last_item) ? $last_item['href'] === $page_link : FALSE;
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
