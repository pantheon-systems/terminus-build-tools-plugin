<?php

namespace Pantheon\TerminusBuildTools\API;

/**
 * WebAPI represents an interface to a remote API (GitHub, BitBucket, GitLab, etc.)
 */
interface WebAPIInterface
{
    public function serviceName();
    public function request($uri, $data = [], $method = 'GET');
    public function pagedRequest($uri, $callback = null);
}
