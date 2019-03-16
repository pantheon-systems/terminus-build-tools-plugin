<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\SiteProviders;

use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;

class SiteEnvironment extends ProviderEnvironment
{
    public function siteName()
    {
        return $this['TERMINUS_SITE'];
    }

    public function setSiteName($siteName)
    {
        $this->makeVariableValuePublic('TERMINUS_SITE');
        $this['TERMINUS_SITE'] = $siteName;
        return $this;
    }

    public function siteToken()
    {
        return $this['TERMINUS_TOKEN'];
    }

    public function setSiteToken($siteName)
    {
        $this['TERMINUS_TOKEN'] = $siteName;
        return $this;
    }

    public function testSiteName()
    {
        return $this['TEST_SITE_NAME'];
    }

    public function setTestSiteName($siteName)
    {
        $this->makeVariableValuePublic('TEST_SITE_NAME');
        $this['TEST_SITE_NAME'] = $siteName;
        return $this;
    }

    public function adminPassword()
    {
        return $this['ADMIN_PASSWORD'];
    }

    public function setAdminPassword($siteName)
    {
        $this['ADMIN_PASSWORD'] = $siteName;
        return $this;
    }

    public function adminEmail()
    {
        return $this['ADMIN_EMAIL'];
    }

    public function setAdminEmail($siteName)
    {
        $this['ADMIN_EMAIL'] = $siteName;
        return $this;
    }

    public function gitEmail()
    {
        return $this['GIT_EMAIL'];
    }

    public function setGitEmail($siteName)
    {
        $this['GIT_EMAIL'] = $siteName;
        return $this;
    }
}
