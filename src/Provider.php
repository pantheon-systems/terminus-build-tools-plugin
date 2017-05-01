<?php

namespace Pantheon\TerminusBuildTools;

use Psr\Log\LoggerAwareInterface;
use Robo\Common\IO;
use Psr\Log\LoggerAwareTrait;
use Pantheon\Terminus\Exceptions\TerminusException;
use Psr\Log\LoggerInterface;
use Robo\Robo;

class Provider implements LoggerAwareInterface {

  use LoggerAwareTrait;
  use IO {
    io as roboIo;
  }


  protected $tmpDirs = [];

  // Create a temporary directory
  public function tempdir($prefix='php', $dir=FALSE)
  {
    $tempfile=tempnam($dir ? $dir : sys_get_temp_dir(), $prefix ? $prefix : '');
    if (file_exists($tempfile)) {
      unlink($tempfile);
    }
    mkdir($tempfile);
    chmod($tempfile, 0700);
    if (is_dir($tempfile)) {
      $this->tmpDirs[] = $tempfile;
      return $tempfile;
    }
  }

  // Passthru for redacted commands
  protected function passthruRedacted($command, $secret)
  {
    $loggedCommand = str_replace($secret, 'REDACTED', $command);
    $command .= " | sed -e 's/$secret/REDACTED/g'";

    $this->passthru($command, $loggedCommand);
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
    //$this->log()->notice("Running {cmd}", ['cmd' => $loggedCommand]);
    passthru($command, $result);

    if ($result != 0) {
      throw new TerminusException('Command `{command}` failed with exit code {status}', ['command' => $loggedCommand, 'status' => $result]);
    }
  }

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

  protected function createBasicAuthenticationCurlChannel($url, $username, $password = '')
  {
    $ch = $this->createAuthorizationHeaderCurlChannel($url);
    curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);
    return $ch;
  }

  /**
   * Returns a logger object for use
   *
   * @return LoggerInterface
   */
  protected function log()
  {
    if (is_null($this->logger)) {
      $container = Robo::getContainer();
      $this->setLogger($container->get('logger'));
    }
    return $this->logger;
  }
}