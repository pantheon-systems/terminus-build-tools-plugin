<?php
/**
 * Terminus Plugin that contain a collection of commands useful during
 * the build step on a [Pantheon](https://www.pantheon.io) site that uses
 * a Git PR workflow.
 *
 * See README.md for usage information.
 */

namespace Pantheon\TerminusBuildTools\Commands;

use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CIProvider;
use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\BaseGitProvider;
use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\GitHub\GitHubProvider;
use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\GitProvider;
use Pantheon\TerminusBuildTools\ServiceProviders\SiteProviders\SiteProvider;

/**
 * Self Info Command
 */
class SelfInfoCommand extends BuildToolsBase
{

    /**
     * Show information about the Terminus Build Tools local environment.
     *
     * @field-labels
     *     build_tools_directory: Build Tools Installation Directory
     *     supported_providers: Providers with Tokens On Your System
     *     releases: Releases
     * @return PropertyList
     *
     * @command build:self:info
     */
    public function info()
    {
        $buildToolsDirectory = str_replace('/src/Commands', '', dirname(__FILE__));

        $providerManager = $this->providerManager();

        $providers = $providerManager->availableProviders();
        foreach ($providers as $provider) {
            $providerClass = new \ReflectionClass($provider);
            if ($providerClass->implementsInterface(GitProvider::class)) {
                $this->createGitProvider($provider);
            }
            elseif ($providerClass->implementsInterface(CIProvider::class)) {
                $this->createCIProvider($provider);
            }
            elseif ($providerClass->implementsInterface(SiteProvider::class)) {
                $this->createSiteProvider($provider);
            }
        }

        $supportedProviders = [];
        foreach ($providerManager->getProviders() as $provider) {
            try {
                $credentialRequests = $provider->credentialRequests();
                foreach ($credentialRequests as $credentialRequest) {
                    $this->providerManager()->credentialManager()->addRequest($credentialRequest);
                    $provider->setCredentials($this->providerManager()->credentialManager());
                }
                $secretValues = $provider->getSecretValues();
                $validProvider = TRUE;
                foreach ($secretValues as $value) {
                    if (strlen($value) > 1) {
                        continue;
                    }
                    else {
                        $validProvider = FALSE;
                    }
                }
                if ($validProvider) {
                    $supportedProviders[] = $provider->getServiceName();
                }
            }
            catch (\Exception $e) {
                // Some providers like to throw a fatal error if they don't have credentials.
                // Catch it here and just keep oging, since they shouldn't be on our list anyway.
            }
        }

        return new PropertyList([
            'build_tools_directory' => $buildToolsDirectory,
            'supported_providers' => implode(', ', $supportedProviders),
            'releases' => 'https://github.com/pantheon-systems/terminus-build-tools-plugin/releases',
        ]);
    }
}
