<?php

namespace Pantheon\TerminusBuildTools\Providers;

use Pantheon\Terminus\Exceptions\TerminusException;

/**
 * Interface GitProvider
 *
 * @package Pantheon\TerminusBuildTools\Providers
 */
class GitProvider {

  /**
   * Main Provider Name
   *
   * @var string
   */
  public $provider = '';

  /**
   * Gets the user's access token.
   */
  public function getToken() {}

  /**
   * Create's a fork of the base repo.
   */
  public function create() {}

  public function execCurlRequest($ch, $service = 'API request')
  {
    $result = curl_exec($ch);
    if(curl_errno($ch))
    {
      throw new TerminusException(curl_error($ch));
    }
    $data = json_decode($result, true);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $errors = [];
    if (isset($data['errors'])) {
      foreach ($data['errors'] as $error) {
        $errors[] = $error['message'];
      }
    }
    if ($httpCode && ($httpCode >= 300)) {
      $errors[] = "Http status code: $httpCode";
    }

    $message = isset($data['message']) ? "{$data['message']}." : '';

    if (!empty($message) || !empty($errors)) {
      throw new TerminusException('{service} error: {message} {errors}', ['service' => $service, 'message' => $message, 'errors' => implode("\n", $errors)]);
    }

    return $data;
  }

  protected function createAuthorizationHeaderCurlChannel($url, $auth = '')
  {
    $headers = [
      'Content-Type: application/json',
      'User-Agent: pantheon/terminus-build-tools-plugin'
    ];

    if (!empty($auth)) {
      $headers[] = "Authorization: token $auth";
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    return $ch;
  }

  protected function setCurlChannelPostData($ch, $postData, $force = false)
  {
    if (!empty($postData) || $force) {
      $payload = json_encode($postData);
      curl_setopt($ch, CURLOPT_POST, 1);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }
  }

  /**
   * Call passthru; throw an exception on failure.
   *
   * @param string $command
   */
  protected function passthru($command, $loggedCommand = '')
  {
    $result = 0;
    $loggedCommand = empty($loggedCommand) ? $command : $loggedCommand;
    // TODO: How noisy do we want to be?
    $this->log()->notice("Running {cmd}", ['cmd' => $loggedCommand]);
    passthru($command, $result);

    if ($result != 0) {
      throw new TerminusException('Command `{command}` failed with exit code {status}', ['command' => $loggedCommand, 'status' => $result]);
    }
  }

  /**
   * Given a source, such as:
   *    pantheon-systems/example-drops-8-composer:dev-lightning-fist-2
   * Return the 'project' portion, including the org, e.g.:
   *    pantheon-systems/example-drops-8-composer
   */
  protected function sourceProjectFromSource($source)
  {
    return preg_replace('/:.*/', '', $source);
  }

  protected function passthruRedacted($command, $secret)
  {
    $loggedCommand = str_replace($secret, 'REDACTED', $command);
    $command .= " | sed -e 's/$secret/REDACTED/g'";

    $this->passthru($command, $loggedCommand);
  }

}