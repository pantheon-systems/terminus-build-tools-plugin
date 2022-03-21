<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\SiteProviders;

use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;

class SiteEnvironment extends ProviderEnvironment
{
    public function siteName()
    {
        return $this['TERMINUS_SITE'];
    }

    public function setSiteName($site_name)
    {
        $this->makeVariableValuePublic('TERMINUS_SITE');
        $this['TERMINUS_SITE'] = $site_name;
        return $this;
    }

    public function siteToken()
    {
        return $this['TERMINUS_TOKEN'];
    }

    public function setSiteToken($site_token)
    {
        $this['TERMINUS_TOKEN'] = $site_token;
        return $this;
    }

    public function testSiteName()
    {
        return $this['TEST_SITE_NAME'];
    }

    public function setTestSiteName($test_site_name)
    {
        $this->makeVariableValuePublic('TEST_SITE_NAME');
        $this['TEST_SITE_NAME'] = $test_site_name;
        return $this;
    }

    public function adminPassword()
    {
        return $this['ADMIN_PASSWORD'];
    }

    public function setAdminPassword($admin_password)
    {
        $this['ADMIN_PASSWORD'] = $admin_password;
        return $this;
    }

    public function adminEmail()
    {
        return $this['ADMIN_EMAIL'];
    }

    public function setAdminEmail($admin_email)
    {
        $this['ADMIN_EMAIL'] = $admin_email;
        return $this;
    }

    public function adminUsername()
    {
        return $this['ADMIN_USERNAME'];
    }

    public function setAdminUsername($admin_username)
    {
        $this['ADMIN_USERNAME'] = $admin_username;
        return $this;
    }

    public function gitEmail()
    {
        return $this['GIT_EMAIL'];
    }

    public function setGitEmail($git_email)
    {
        $this['GIT_EMAIL'] = $git_email;
        return $this;
    }

    public function setComposerAuth($composerAuth) {
      $this['COMPOSER_AUTH'] = $composerAuth;
      return $this;
    }

    public function getComposerAuth() {
      return $this['COMPOSER_AUTH'];
    }

    public function siteProfile()
    {
        return $this['SITE_PROFILE'];
    }

    public function setSiteProfile($site_profile)
    {
        $this['SITE_PROFILE'] = $site_profile;
        return $this;
    }

}
