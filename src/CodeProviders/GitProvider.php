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

  // TODO: At the moment, this takes multidev environment names,
  // e.g.:
  //   pr-dc-worka
  // And compares them against a list of branches, e.g.:
  //   dc-workaround
  //   lightning-fist-2
  //   composer-merge-pantheon
  // In its current form, the 'pr-' is stripped from the beginning of
  // the environment name, and then a 'begins-with' test is done. This
  // is not perfect, but if it goes wrong, the result will be that a
  // multidev environment that should have been eligible for deletion will
  // not be deleted.
  //
  // This could be made better if we could fetch the build-metadata.json
  // file from the repository root of each multidev environment, which would
  // give us the correct branch name for every environment. We could do
  // this without too much trouble via rsync; this might be a little slow, though.
  public function preserveEnvsWithBranches($oldestEnvironments, $multidev_delete_pattern) {
    $remoteBranch = 'origin';

    // Update the local repository -- prune / add remote branches.
    // We could use `git remote prune origin` to only prune remote branches.
    $this->passthru('git remote update --prune origin');

    // List all of the remote branches
    $outputLines = $this->exec('git branch -ar');

    // Remove branch lines that do not begin with 'origin/'
    $outputLines = array_filter(
      $outputLines,
      function ($item) use ($remoteBranch) {
        return preg_match("%^ *$remoteBranch/%", $item);
      }
    );

    // Strip the 'origin/' from the beginning of each branch line
    $outputLines = array_map(
      function ($item) use ($remoteBranch) {
        return preg_replace("%^ *$remoteBranch/%", '', $item);
      },
      $outputLines
    );

    return $this->filterBranches($oldestEnvironments, $outputLines, $multidev_delete_pattern);
  }

  protected function projectFromRemoteUrl($url) {
    return preg_replace('#[^:/]*[:/]([^/:]*/[^.]*)\.git#', '\1', str_replace('https://', '', $url));
  }

  protected function filterBranches($oldestEnvironments, $branchList, $multidev_delete_pattern) {
    // Filter environments that have matching remote branches in origin
    return array_filter(
      $oldestEnvironments,
      function ($item) use ($branchList, $multidev_delete_pattern) {
        $match = $item;
        // If the name is less than the maximum length, then require
        // an exact match; otherwise, do a 'starts with' test.
        if (strlen($item) < 11) {
          $match .= '$';
        }
        // Strip the multidev delete pattern from the beginning of
        // the match. The multidev env name was composed by prepending
        // the delete pattern to the branch name, so this recovers
        // the branch name.
        $match = preg_replace("%$multidev_delete_pattern%", '', $match);
        // Constrain match to only match from the beginning
        $match = "^$match";

        // Find items in $branchList that match $match.
        $matches = preg_grep ("%$match%i", $branchList);
        return empty($matches);
      }
    );
  }

}