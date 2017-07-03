<?php
namespace Pantheon\TerminusBuildTools\Task\Repository;

trait Tasks
{
    /**
     * @return RepositoryCreate
     */
    protected function taskRepositoryCreate()
    {
        return $this->task(RepositoryCreate::class);
    }
}
