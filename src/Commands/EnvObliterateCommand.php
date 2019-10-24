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
 * Env Obliterate Command
 */
class EnvObliterateCommand extends BuildToolsBase
{
    /**
     * Destroy a Pantheon site that was created by the build:project:create command.
     *
     * @command build:env:obliterate
     * @aliases build-env:obliterate
     */
    public function obliterate($site_name)
    {
        $site = $this->getSite($site_name);

        // Fetch the build metadata from the specified site name and
        // look up the URL to the repository stored therein.
        $url = $this->getUrlFromBuildMetadata("{$site_name}.dev");

        // Create a git repository service provider appropriate to the URL
        $gitProvider = $this->inferGitProviderFromUrl($url);

        // Ensure that all of our providers are given the credentials they need.
        // Providers are not usable until this is done.
        $this->providerManager()->validateCredentials();

        // Do nothing without confirmation
        if (!$this->confirm('Are you sure you want to delete {site} AND its corresponding Git provider repository {url} and CI configuration?', ['site' => $site->getName(), 'url' => $url])) {
            return;
        }

        $this->log()->notice('About to delete {site} and its corresponding remote repository {url} and CI configuration.', ['site' => $site->getName(), 'url' => $url]);

        // CI configuration is automatically deleted when the repository is deleted.
        // Is this true for all CI providers? GitLab / Bitbucket probably work this way.

        // Use the GitHub API to delete the GitHub project.
        $project = $this->projectFromRemoteUrl($url);

        // Delete the remote git repository.
        $gitProvider->deleteRepository($project);
        $this->log()->notice('Deleted {project}', ['project' => $project]);

        // Use the Terminus API to delete the Pantheon site.
        $site->delete();
        $this->log()->notice('Deleted {site} from Pantheon', ['site' => $site_name,]);
    }

    /**
     * @command build:repo:delete
     *
     * EXPERIMENTAL COMMAND
     *
     * build:env:obliterate only works when the Pantheon site and git repo
     * have been linked up, e.g. if build:project:create gets most of the
     * way through. If build:project:create does not finish, this command
     * is useful in getting rid of leftover repos. Not sure if we should
     * continue to support this or not.
     */
    public function deleteRepo($url)
    {
        $this->log()->notice('Look up provider from {url}', ['url' => $url]);
        // Create a git repository service provider appropriate to the URL
        $gitProvider = $this->inferGitProviderFromUrl($url);

        $this->log()->notice('About to validate credentials');

        // Ensure that all of our providers are given the credentials they need.
        // Providers are not usable until this is done.
        $this->providerManager()->validateCredentials();

        $this->log()->notice('Look up project');

        $project = $this->projectFromRemoteUrl($url);

        $this->log()->notice('Project is {project}', ['project' => $project]);

        // Delete the remote git repository.
        $gitProvider->deleteRepository($project);
        $this->log()->notice('Deleted {project}', ['project' => $project]);
    }
}
