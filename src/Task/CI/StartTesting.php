<?php
namespace Pantheon\TerminusBuildTools\Task\CI;

use Robo\Result;

class StartTesting extends Base
{
    public function run()
    {
        $this->provider->startTesting($this->ci_env);

        return Result::success($this);
    }
}
