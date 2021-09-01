<?php
namespace Pantheon\TerminusBuildTools\Task\CI;

use Robo\Result;

class StartTesting extends Base
{
    protected $cluCronPattern;

    public function run()
    {
        $this->provider->startTesting($this->ci_env, $this->cluCronPattern);

        return Result::success($this);
    }

    public function setCluCronSchedule($cluCronPattern) {
      $this->cluCronPattern = $cluCronPattern;
    }
}
