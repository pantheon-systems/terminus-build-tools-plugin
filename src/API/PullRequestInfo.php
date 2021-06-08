<?php

namespace Pantheon\TerminusBuildTools\API;

/**
 * PullRequestInfo caches info about pull requests
 */
class PullRequestInfo
{
    protected $prNumber;
    protected $isClosed;
    protected $branchName;

    public function __construct($prNumber, $isClosed, $branchName)
    {
        $this->prNumber = $prNumber;
        $this->isClosed = $isClosed;
        $this->branchName = $branchName;
    }

    public function prNumber()
    {
        return $this->prNumber;
    }

    public function isClosed()
    {
        return $this->isClosed;
    }

    public function branchName()
    {
        return $this->branchName;
    }
}
