<?php
namespace Pantheon\TerminusBuildTools\Task\Ssh;

use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CIState;

interface PrivateKeyReciever
{
    public function addPrivateKey(CIState $ci_env, $privateKey);
}
