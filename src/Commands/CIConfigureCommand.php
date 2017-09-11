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
     * Configure CI Tests for a Pantheon site created from the specified
     * GitHub repository
     *
     * @authorize
     *
     * @command build:ci:configure
     * @aliases build-env:ci:configure
     * @param $site_name The pantheon site to test.
     * @param $target_project The GitHub org/project to build the Pantheon site from.
     */
    public function configureCI(InputInterface $input, OutputInterface $output,
        $site_name,
        $target_project,
        $options = [
            'test-site-name' => '',
            'email' => '',
            'admin-password' => '',
            'admin-email' => '',
            'env' => [],
            'ci' => 'circle',
            'git' => 'github'
        ])
    {
        $site = $this->getSite($site_name);
        $options = $this->validateOptionsAndSetDefaults($options);

        // Get the build metadata from the Pantheon site. Fail if there is
        // no build metadata on the master branch of the Pantheon site.
        $buildMetadata = $this->retrieveBuildMetadata("{$site_name}.dev") + ['url' => ''];
        $this->createProviders($input->getOption('git'), $input->getOption('ci'));
        
        $desired_url = $this->git_provider->gitCommitURL($target_project);
        if (!empty($buildMetadata['url']) && ($desired_url != $buildMetadata['url'])) {
            throw new TerminusException('The site {site} is already configured to test {url}; you cannot use this site to test {desired}.', ['site' => $site_name, 'url' => $buildMetadata['url'], 'desired' => $desired_url]);
        }

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
