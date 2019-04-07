<?php
namespace Pantheon\TerminusBuildTools\Task\Quicksilver;

use Robo\Result;

class PushbackSetup
  extends Base
{

    protected $dir;

    public function dir($siteDir)
    {
        $this->dir = $siteDir;
    }

    public function run()
    {
        //$target_project = $this->provider->createRepository($this->dir, $this->target, $this->target_org);
        $this->git_provider->writeBuildProvidersFile($this->git_provider->getServiceName(), $this->ci_provider->getServiceName(), $this->dir);
        $this->git_provider->commitCode($this->dir, "Initialize build-providers.json file.");
        $this->logger()->notice('Created build-providers.json');

        return Result::success($this);
    }
}
