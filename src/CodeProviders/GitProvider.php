<?php

namespace Pantheon\TerminusBuildTools\CodeProviders;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusBuildTools\Provider;
use Robo\Common\IO;
use Pantheon\Terminus\Style\TerminusStyle;

/**
 * Interface GitProvider
 *
 * @package Pantheon\TerminusBuildTools\CodeProviders
 */
class GitProvider extends Provider {

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
  public function create($source, $target, $git_org, $git_token, $stability) {}

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

  /**
   * Push code to a git provider.
   */
  public function push($git_token, $target_project, $repositoryDir) {}

  /**
   * Get the git repository URL of the given target project.
   */
  public function site($target_project) {}

  /**
   * Get the ssh git repo URL of the given target project.
   */
  public function desiredURL($target_project) {}

  /**
   * Delete a given project from a git provider.
   */
  public function delete($target_project, $git_token) {}

}