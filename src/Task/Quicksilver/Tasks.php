<?php
namespace Pantheon\TerminusBuildTools\Task\Quicksilver;

trait Tasks
{
  /**
   * @return PushbackSetup
   */
  protected function taskPushbackSetup()
  {
    return $this->task(PushbackSetup::class);
  }
}
