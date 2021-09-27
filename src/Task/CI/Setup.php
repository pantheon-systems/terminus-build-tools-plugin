<?php
namespace Pantheon\TerminusBuildTools\Task\CI;

use Robo\Result;
use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\GithubActions\GithubActionsProvider;
use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;

class Setup extends Base
{
    protected $dir;
    protected $hasMultidevCapability;

    public function dir($dir)
    {
        $this->dir = $dir;
    }

    public function hasMultidevCapability($hasMultidevCapability)
    {
        $this->hasMultidevCapability = $hasMultidevCapability;
    }

    public function run()
    {
        $siteAttributes = $this->ci_env->getState('site');
        $site_name = $siteAttributes->siteName();

        $readme = isset($this->dir) ? file_get_contents("{$this->dir}/README.md") : '';

        $circleBadge = $this->provider->badge($this->ci_env);

        // Replace the 'ci | none' badge with the Circle badge. If
        // there is no badge placeholder, then put the Circle badge
        // near the front of the README, ideally after the '# Project Title'.
        if (preg_match('#!\[CI none\]\([^)]*\)#', $readme)) {
            $readme = preg_replace('#!\[CI none\]\([^)]*\)#', $circleBadge, $readme);
        }
        else {
            $readme = preg_replace('/^(#[^\n]*\n\n|)/', "\\1$circleBadge", $readme);
        }

        // If this site cannot create multidev environments, then configure
        // it to always run tests on the dev environment.
        if (!$this->hasMultidevCapability) {
            $this->ci_env->set('env', 'TERMINUS_ENV', 'dev');
            $ci_page = $this->provider->projectUrl($this->ci_env);
            $readme .= "\n\n## IMPORTANT NOTE\n\nAt the time of creation, the Pantheon site being used for testing did not have multidev capability. The test suites were therefore configured to run all tests against the dev environment. If the test site is later given multidev capabilities, you must [visit the environment variable configuration page]($ci_page) and delete the environment variable `TERMINUS_ENV`. If you do this, then the test suite will create a new multidev environment for every pull request that is tested.";
        }
        if (isset($this->dir)) {
            file_put_contents("{$this->dir}/README.md", $readme);

            passthru("git -C {$this->dir} add README.md");
            exec("git -C {$this->dir} status --porcelain", $outputLines, $status);
            if (!empty($outputLines)) {
                passthru("git -C {$this->dir} commit -m 'Update CI badge in README'");
            }
        }

        // Print a message listing the variables we're about to set
        $env = $this->ci_env->getAggregateState();
        $this->logger()->notice('Define CI environment variables: {keys}', ['keys' => implode(',', array_keys($env))]);

        $envState = new ProviderEnvironment();
        $this->ci_env->storeState('temp_settings', $envState);
        $this->ci_env->set('temp_settings', 'CURRENT_WORKDIR', $this->dir);

        // Tell the provider to set the variables
        $this->provider->configureServer($this->ci_env);

        return Result::success($this);
    }
}
