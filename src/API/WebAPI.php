<?php

namespace Pantheon\TerminusBuildTools\API;

use Pantheon\TerminusBuildTools\API\WebAPI;
use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;
use Pantheon\TerminusBuildTools\ServiceProviders\ServiceTokenStorage;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Pantheon\Terminus\Exceptions\TerminusException;

/**
 * WebAPI is an abstract class for managing web APIs for different services.
 */
abstract class WebAPI implements WebAPIInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var ServiceTokenStorage */
    protected $serviceTokenStorage;

    public function __construct(ServiceTokenStorage $serviceTokenStorage)
    {
        $this->serviceTokenStorage = $serviceTokenStorage;
    }

    abstract protected function apiClient();

    abstract protected function isPagedResponse($headers);

    abstract protected function getPagerInfo($headers);

    abstract protected function isLastPage($page_link, $pager_info);

    abstract protected function getNextPageUri($pager_info);

    public function request($uri, $data = [], $method = 'GET')
    {
        $res = $this->sendRequest($uri, $data, $method);

        $resultData = json_decode($res->getBody(), true);
        $httpCode = $res->getStatusCode();

        return $this->processResponse($resultData, $httpCode);
    }

    public function pagedRequest($uri, $callback = null)
    {
        $res = $this->sendRequest($uri);

        $resultData = json_decode($res->getBody(), true);
        $httpCode = $res->getStatusCode();

        // Remember all of the collected data in $accumulatedData
        $accumulatedData = $resultData;

        // The request may be against a paged collection. If that is the case, traverse the "next" links sequentially
        // (since it's simpler and PHP doesn't have non-blocking I/O) until the end and accumulate the results.
        $headers = $res->getHeaders();
        // Check if the array is numeric. Otherwise we can't consider this a collection.
        if ($this->isSequentialArray($resultData) && $this->isPagedResponse($headers)) {
            $pager_info = $this->getPagerInfo($headers);
            $isDone = false;
            while (($httpCode == 200) && !$isDone && $this->checkPagedCallback($resultData, $callback)) {
                $isDone = $this->isLastPage($uri, $pager_info);
                $uri = $this->getNextPageUri($pager_info);

                $res = $this->sendRequest($uri);
                $httpCode = $res->getStatusCode();
                $resultData = json_decode($res->getBody(), true);

                if (!is_null($resultData))
                {
                    $accumulatedData = array_merge_recursive(
                        $accumulatedData,
                        $resultData
                    );
                }

            }
        }

        return $this->processResponse($accumulatedData, $httpCode);
    }

    protected function sendRequest($uri, $data = [], $method = 'GET')
    {
        $guzzleParams = [];
        if (!empty($data)) {
            if  ($method == 'GET') {
                $method = 'POST';
            }
            $guzzleParams['json'] = $data;
        }

        $this->logger->notice('Call {service} API: {method} {uri}', ['service' => $this->serviceHumanReadableName(), 'method' => $method, 'uri' => $uri]);

        $client = $this->apiClient();
        return $client->request($method, $uri, $guzzleParams);
    }

    protected function checkPagedCallback($resultData, $callback)
    {
        if (!$callback) {
            return true;
        }

        return $callback($resultData);
    }

    protected function processResponse($resultData, $httpCode)
    {
        $errors = [];
        $message = '';
        if (isset($resultData['errors'])) {
            foreach ($resultData['errors'] as $error) {
                $errors[] = $error['message'];
            }
        }
        if ($httpCode && ($httpCode >= 300)) {
            $errors[] = "Http status code: $httpCode";
            $message = isset($resultData['message']) ? "{$resultData['message']}." : '';
        }

        if (!empty($message) || !empty($errors)) {
            throw new TerminusException('error: {message} {errors}', ['message' => $message, 'errors' => implode("\n", $errors)]);
        }

        return $resultData;
    }

    protected function isSequentialArray($input)
    {
        if (!is_array($input)) {
            return FALSE;
        }
        if (empty($input)) {
            return TRUE;
        }
        $keys = array_keys($input);
        $keys_of_keys = array_keys($keys);
        for ($i = 0; $i < count($keys); $i++) {
            if ($keys[$i] !== $keys_of_keys[$i]) {
                return FALSE;
            }
        }
        return TRUE;
    }
}
