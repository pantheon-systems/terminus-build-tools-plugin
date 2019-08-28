<?php
namespace Pantheon\TerminusBuildTools\Utility;

/**
 * URLParsing contains a set of tools for parsing URLs to retrieve pieces of a
 * project's URL information from them.
 */
class UrlParsing
{

    /**
     * orgUserFromRemoteUrl converts from a url e.g. https://github.com/org/repo
     * to the "org" portion of the provided url.
     */
    public static function orgUserFromRemoteUrl($url)
    {
        if ((strpos($url, 'https://') !== false) || (strpos($url, 'http://') !== false))
        {
            $parsed_url = parse_url($url);
            $path_components = explode('/', substr(str_replace('.git', '', $parsed_url['path']), 1));
            array_pop($path_components);
            return count($path_components) > 1 ? implode('/', $path_components) : $path_components[0];
        }
        return preg_match('/^(\w+)@(\w+).(\w+):(.+)\/(.+)(.git)$/', $url, $matches) ? $matches[4] : '';
    }

    /**
     * repositoryFromRemoteUrl converts from a url e.g. https://github.com/org/repo
     * to the "repo" portion of the provided url.
     */
    public static function repositoryFromRemoteUrl($url)
    {
        if ((strpos($url, 'https://') !== false) || (strpos($url, 'http://') !== false))
        {
            $parsed_url = parse_url($url);
            $path_components = explode('/', substr(str_replace('.git', '', $parsed_url['path']), 1));
            return array_pop($path_components);
        }
        return preg_match('/^(\w+)@(\w+).(\w+):(.+)\/(.+)(.git)$/', $url, $matches) ? $matches[5] : '';
    }


}
