<?php
namespace Pantheon\TerminusBuildTools\Task\Ssh;

use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CIState;

interface KeyPairReciever
{
    public function addKeyPair(CIState $ci_env, $publicKey, $privateKey);
}
