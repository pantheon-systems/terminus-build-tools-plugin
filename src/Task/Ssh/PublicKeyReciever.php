<?php
namespace Pantheon\TerminusBuildTools\Task\Ssh;

use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CIState;

interface PublicKeyReciever
{
    public function addPublicKey(CIState $ci_env, $publicKey);
}
