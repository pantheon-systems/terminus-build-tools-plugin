<?php
/**
 * Terminus Plugin that contain a collection of commands useful during
 * the build step on a [Pantheon](https://www.pantheon.io) site that uses
 * a GitHub PR workflow.
 *
 * See README.md for usage information.
 */

namespace Pantheon\TerminusBuildTools\Commands;

/**
 * Project Repair Command
 */
class ProjectInfoCommand extends BuildToolsBase
{
    use \Pantheon\TerminusBuildTools\Task\Ssh\Tasks;
    use \Pantheon\TerminusBuildTools\Task\CI\Tasks;

    /**
     * Show build info attached to a site created by the
     * build:project:create command.
     *
     * @command build:project:info
     */
    public function info(
        $site_name,
        $options = [
            'ci' => '',
        ])
    {
        // Fetch the build metadata
        $buildMetadata = $this->retrieveBuildMetadata("{$site_name}.dev");
        $url = $this->getMetadataUrl($buildMetadata);

        $this->log()->notice('Build metadata: {metadata}', ['metadata' => var_export($buildMetadata, true)]);

        // Create a git repository service provider appropriate to the URL
        $this->git_provider = $this->inferGitProviderFromUrl($url);

        // Extract just the project id from the URL
        $target_project = $this->projectFromRemoteUrl($url);
        $this->git_provider->getEnvironment()->setProjectId($target_project);

        $ci_provider_class_or_alias = $this->selectCIProvider($this->git_provider->getServiceName(), $options['ci']);

        $this->ci_provider = $this->createCIProvider($ci_provider_class_or_alias);

        // Ensure that all of our providers are given the credentials they need.
        // Providers are not usable until this is done.
        $this->providerManager()->validateCredentials();

        // Fetch the project name.
        $this->log()->notice('Found project {project}', ['project' => $target_project]);

    }
}
