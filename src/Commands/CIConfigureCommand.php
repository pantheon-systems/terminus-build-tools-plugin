<?php
/**
 * Terminus Plugin that contain a collection of commands useful during
 * the build step on a [Pantheon](https://www.pantheon.io) site that uses
 * a GitHub PR workflow.
 *
 * See README.md for usage information.
 */

namespace Pantheon\TerminusBuildTools\Commands;

use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\ProcessUtils;
use Consolidation\AnnotatedCommand\AnnotationData;
use Consolidation\AnnotatedCommand\CommandData;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Semver\Comparator;

/**
 * CI Configure Command
 */
class CIConfigureCommand extends BuildToolsBase
{
    use \Pantheon\TerminusBuildTools\Task\CI\Tasks;

    /**
     * Configure CI Tests for a Pantheon site created via build:project:create.
     *
     * @authorize
     *
     * @command build:ci:configure
     * @aliases build-env:ci:configure
     * @param $site_name The pantheon site to test.
     */
    public function configureCI($site_name,
        $options = [
            'email' => '',
            'admin-password' => '',
            'admin-email' => '',
            'ci' => 'circle'
        ])
    {
        $site = $this->getSite($site_name);
        $options = $this->validateOptionsAndSetDefaults($options);

        // Get the build metadata from the Pantheon site. Fail if there is
        // no build metadata on the master branch of the Pantheon site.
        $buildMetadata = $this->retrieveBuildMetadata("{$site_name}.dev") + ['url' => ''];
        
        if (empty($buildMetadata['url'])) {
            throw new TerminusException('The site {site} does not have the required build metadata. This command can only be used for sites that have been correctly initialized with build:project:create.', ['site' => $site_name]);
        }

        // Create a git repository service provider appropriate to the URL
        $this->inferGitProviderFromUrl($buildMetadata['url']);
        $target_project = $this->projectFromRemoteUrl($buildMetadata['url']);

        // Initialize providers
        $this->createCIProvider($options['ci']);

        // Ensure that all of our providers are given the credentials they requested.
        $this->providerManager()->validateCredentials();

        // Prepare for builder
        $ci_env = $this->getCIEnvironment($site_name, $options);
        $this->git_provider->getEnvironment()->setProjectId($target_project);
        $ci_env->storeState('repository', $this->git_provider->getEnvironment());
        
        // Use builder to set up CI
        $builder = $this->collectionBuilder();
        $builder->taskCISetup()
            ->provider($this->ci_provider)
            ->environment($ci_env);

        return $builder;
    }
}
