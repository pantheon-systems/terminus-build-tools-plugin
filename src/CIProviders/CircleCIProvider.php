<?php

namespace Pantheon\TerminusBuildTools\CIProviders;

use Pantheon\Terminus\Exceptions\TerminusException;

class CircleCIProvider extends CIProvider {

  public $provider = 'circleci';

  /**
   * {@inheritdoc}
   */
  public function getToken() {
    // Ask for a Circle token if one is not available.
    $circle_token = getenv('CIRCLE_TOKEN');
    while (empty($circle_token)) {
      $circle_token = $this->io()->askHidden("Please generate a Circle CI personal API token by visiting the page:\n\n    https://circleci.com/account/api\n\n For more information, see:\n\n    https://circleci.com/docs/api/v1-reference/#getting-started\n\n Then, enter it here:");
      $circle_token = trim($circle_token);
      putenv("CIRCLE_TOKEN=$circle_token");

      // Validate that the CircleCI token looks correct. If not, prompt again.
      if ((strlen($circle_token) < 40) || preg_match('#[^0-9a-fA-F]#', $circle_token)) {
        $this->log()->warning('CircleCI tokens should be 40-character strings containing only the letters a-f and digits (0-9). Please enter your token again.');
        $circle_token = '';
      }
    }

    return $circle_token;
  }

  /**
   * {@inheritdoc}
   */
  public function getBadge($target_project) {
    return "[![CircleCI](https://circleci.com/gh/{$target_project}.svg?style=shield)](https://circleci.com/gh/{$target_project})";
  }

  /**
   * {@inheritdoc}
   */
  public function prepare($site, $options, $terminus_token, $git_token) {
    $options += [
      'test-site-name' => '',
      'email' => '',
      'admin-password' => '',
      'admin-email' => '',
      'env' => [],
    ];

    $test_site_name = $options['test-site-name'];
    $git_email = $options['email'];
    $admin_password = $options['admin-password'];
    $admin_email = $options['admin-email'];
    $extra_env = $options['env'];

    if (empty($test_site_name)) {
      $test_site_name = $site->getName();
    }

    // We should always be authenticated by the time we get here, but
    // we will test just to be sure.
    if (empty($terminus_token)) {
      throw new TerminusException("Please generate a Pantheon machine token, as described in https://pantheon.io/docs/machine-tokens/. Then log in via: \n\nterminus auth:login --machine-token=my_machine_token_value");
    }

    // Set up Circle CI and run our first test.
    $circle_env = [
      'TERMINUS_TOKEN' => $terminus_token,
      'TERMINUS_SITE' => $site->getName(),
      'TEST_SITE_NAME' => $test_site_name,
      'ADMIN_PASSWORD' => $admin_password,
      'ADMIN_EMAIL' => $admin_email,
      'GIT_EMAIL' => $git_email,
    ];
    // If this site cannot create multidev environments, then configure
    // it to always run tests on the dev environment.
    $settings = $site->get('settings');

    if (!isset($settings->max_num_cdes) || $settings->max_num_cdes <= 0) {
      $circle_env['TERMINUS_ENV'] = 'dev';
    }

    if ($git_token) {
      $circle_env['GIT_TOKEN'] = $git_token;
    }

    // Add in extra environment provided on command line via
    // --env='key=value' --env='another=v2'
    foreach ($extra_env as $env) {
      list($key, $value) = explode('=', $env, 2) + ['',''];
      if (!empty($key) && !empty($value)) {
        $circle_env[$key] = $value;
      }
    }

    return $circle_env;
  }

  /**
   * {@inheritdoc}
   */
  public function configure($target_project, $ci_token, $ci_env, $current_session) {
    $this->log()->notice('Configure Circle CI');

    $site_name = $ci_env['TERMINUS_SITE'];
    $git_email = $ci_env['GIT_EMAIL'];
    $target_label = strtr($target_project, '/', '-');

    $circle_url = "https://circleci.com/api/v1.1/project/github/$target_project";
    $this->setCircleEnvironmentVars($circle_url, $ci_token, $ci_env);

    // Create an ssh key pair dedicated to use in these tests.
    // Change the email address to "user+ci-SITE@domain.com" so
    // that these keys can be differentiated in the Pantheon dashboard.
    $ssh_key_email = str_replace('@', "+ci-{$target_label}@", $git_email);
    $this->log()->notice('Create ssh key pair for {email}', ['email' => $ssh_key_email]);
    list($publicKey, $privateKey) = $this->createSshKeyPair($ssh_key_email, $site_name . '-key');
    $this->addPublicKeyToPantheonUser($current_session, $publicKey);
    $this->addPrivateKeyToCircleProject($circle_url, $ci_token, $privateKey);

    // Follow the project (start a build)
    $this->circleFollow($circle_url, $ci_token);
  }

  protected function setCircleEnvironmentVars($circle_url, $token, $env)
  {
    foreach ($env as $key => $value) {
      $data = ['name' => $key, 'value' => $value];
      $this->curlCircleCI($data, "$circle_url/envvar", $token);
    }
  }

  protected function curlCircleCI($data, $url, $auth)
  {
    $this->log()->notice('Call CircleCI API: {uri}', ['uri' => $url]);
    $ch = $this->createBasicAuthenticationCurlChannel($url, $auth);
    $this->setCurlChannelPostData($ch, $data, true);
    return $this->execCurlRequest($ch, 'CircleCI');
  }

  protected function addPrivateKeyToCircleProject($circle_url, $token, $privateKey)
  {
    $privateKeyContents = file_get_contents($privateKey);
    $data = [
      'hostname' => 'drush.in',
      'private_key' => $privateKeyContents,
    ];
    $this->curlCircleCI($data, "$circle_url/ssh-key", $token);
  }

  protected function circleFollow($circle_url, $token)
  {
    $this->curlCircleCI([], "$circle_url/follow", $token);
  }
}