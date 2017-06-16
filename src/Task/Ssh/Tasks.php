<?php
namespace Pantheon\TerminusBuildTools\Task\Ssh;

trait Tasks
{
    /**
     * @return CreateKeys
     */
    protected function taskCreateKeys()
    {
        return $this->task(CreateKeys::class);
    }
}
