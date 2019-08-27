<?php

namespace Pantheon\TerminusBuildTools\Tests;

use PHPUnit\Framework\TestCase;
use Pantheon\TerminusBuildTools\Utility\UrlParsing;

final class UrlParsingTest extends TestCase
{

    /**
     * @dataProvider gitUrlProvider
     */
    public function testCanRetrieveRepositoryFromRemoteUrl($url, $expected_org, $expected_repo)
    {
        $this->assertEquals($expected_repo, UrlParsing::repositoryFromRemoteUrl($url));
    }

    /**
     * @dataProvider gitUrlProvider
     */
    public function testCanRetrieveOrganizationFromRemoteUrl($url, $expected_org)
    {
        $this->assertEquals($expected_org, UrlParsing::orgUserFromRemoteUrl($url));
    }

    public function gitUrlProvider()
    {
        return [
            ['https://www.github.com/pantheon-systems/terminus-build-tools-plugin.git', 'pantheon-systems', 'terminus-build-tools-plugin'],
            ['https://www.github.com/pantheon-systems/terminus-build-tools-plugin', 'pantheon-systems', 'terminus-build-tools-plugin'],
            ['git@github.com:rvtraveller/bt-test-040819-gh-1.git', 'rvtraveller', 'bt-test-040819-gh-1'],
            ['https://gitlab.com/rvtraveller/example-gitlab-bt-repo', 'rvtraveller', 'example-gitlab-bt-repo'],
            ['https://my-custom-gitlab-host.com/rvtraveller/example-custom-bt-repo', 'rvtraveller', 'example-custom-bt-repo'],
            ['http://insecure-custom-gitlab-host/rvtraveller/example-insecure-custom-gl-bt-repo', 'rvtraveller', 'example-insecure-custom-gl-bt-repo'],
            ['https://gitlab-ci-token:redactedtoken@custom-git-host.com/rvtraveller/subgroup/smokey.git', 'rvtraveller/subgroup', 'smokey'],
            ['https://bitbucket.org/pantheon-systems/example-drops-8-composer', 'pantheon-systems', 'example-drops-8-composer'],
            ['git@bitbucket.org:pantheon-systems/example-wordpress-composer.git', 'pantheon-systems', 'example-wordpress-composer'],
        ];
    }

}