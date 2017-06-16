<?php
namespace Pantheon\TerminusBuildTools\Task\CI;

trait Tasks
{
    /**
     * @return Configure
     */
    protected function taskCIConfigure()
    {
        return $this->task(Configure::class);
    }

    /**
     * @return StartTesting
     */
    protected function taskCIStartTesting()
    {
        return $this->task(StartTesting::class);
    }
}
