<?php

namespace Pantheon\TerminusBuildTools\CIProviders;

use Pantheon\Terminus\Models\Site;
use Pantheon\TerminusBuildTools\Provider;

class CIProvider extends Provider {

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
   * Gets the badge for the CI System.
   */
  public function getBadge($target_project) {}

  /**
   * Prepare the CI Environment.
   *
   * @param $site Site
   */
  public function prepare($site, $options, $terminus_token, $git_token) {}

  /**
   * Configure the CI Environment.
   */
  public function configure($target_project, $ci_token, $ci_env, $current_session) {}

  /**
   * Create a unique ssh key pair to use in testing
   *
   * @param string $ssh_key_email
   * @param string $prefix
   * @return [string, string]
   */
  protected function createSshKeyPair($ssh_key_email, $prefix = 'id')
  {
    $tmpkeydir = $this->tempdir('ssh-keys');

    $privateKey = "$tmpkeydir/$prefix";
    $publicKey = "$privateKey.pub";

    $this->passthru("ssh-keygen -t rsa -b 4096 -f $privateKey -N '' -C '$ssh_key_email'");

    return [$publicKey, $privateKey];
  }

  protected function addPublicKeyToPantheonUser($session, $publicKey)
  {
    $session->getUser()->getSSHKeys()->addKey($publicKey);
  }
}