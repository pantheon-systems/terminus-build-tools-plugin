<?php
namespace Pantheon\TerminusBuildTools\Task\CI;

use Robo\Task\BaseTask;
use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CIState;
use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CIProvider;

abstract class Base extends BaseTask
{
    /** var CIProvider */
    protected $provider;
    /** var CIState */
    protected $ci_env;

    public function environment(CIState $ci_env)
    {
        $this->ci_env = $ci_env;
    }

    public function provider(CIProvider $provider)
    {
        $this->provider = $provider;
    }

}
