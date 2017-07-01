<?php
namespace Pantheon\TerminusBuildTools\Task\CI;

trait Tasks
{
    /**
     * @return Setup
     */
    protected function taskCISetup()
    {
        return $this->task(Setup::class);
    }

    /**
     * @return StartTesting
     */
    protected function taskCIStartTesting()
    {
        return $this->task(StartTesting::class);
    }
}
