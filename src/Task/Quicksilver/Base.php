<?php
namespace Pantheon\TerminusBuildTools\Task\Quicksilver;

use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CIProvider;
use Robo\Task\BaseTask;
use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\GitProvider;

abstract class Base extends BaseTask
{
  /** var GitProvider */
  protected $git_provider;
  protected $ci_provider;

  public function provider(GitProvider $git_provider, CIProvider $ci_provider)
  {
    $this->git_provider = $git_provider;
    $this->ci_provider = $ci_provider;
  }
}
