<?php
namespace Pantheon\TerminusBuildTools\Task\Repository;

use Robo\Result;

class RepositoryCreate extends Base
{
    protected $target;
    protected $target_org;
    protected $dir;

    public function target($target)
    {
        $this->target = $target;
        return $this;
    }

    public function owningOrganization($target_org)
    {
        $this->target_org = $target_org;
    }

    public function dir($siteDir)
    {
        $this->dir = $siteDir;
    }

    public function run()
    {
        $target_project = $this->provider->createRepository($this->dir, $this->target, $this->target_org);
        $this->logger()->notice('Created repository {target}', ['target' => $target_project]);
        // $repositoryAttributes->setProjectId($target_project);

        return Result::success($this);
    }
}
