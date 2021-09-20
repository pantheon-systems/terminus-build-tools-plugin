<?php
/**
 * Terminus Plugin that contain a collection of commands useful during
 * the build step on a [Pantheon](https://www.pantheon.io) site that uses
 * a GitHub PR workflow.
 *
 * See README.md for usage information.
 */

namespace Pantheon\TerminusBuildTools\Commands;

use Consolidation\AnnotatedCommand\AnnotationData;
use Consolidation\AnnotatedCommand\CommandData;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;

/**
 * Project Repair Command
 */
class ProjectRepairCommand extends BuildToolsBase
{
    use \Pantheon\TerminusBuildTools\Task\Ssh\Tasks;
    use \Pantheon\TerminusBuildTools\Task\CI\Tasks;

    /**
     * Initialize the default value for selected options.
     *
     * @hook init build:project:repair
     */
    public function initOptionValues(InputInterface $input, AnnotationData $annotationData)
    {
        $this->log()->notice('build:project:repair @hook init');

        // Get the site name argument. If there is none, skip init
        // and allow the command to fail with "not enough arguments"
        $site_name = $input->getArgument('site_name');
        if (empty($site_name)) {
            $site_name = $input->getOption('pantheon-site');
        }
        if (empty($site_name)) {
            return;
        }

        // Fetch the build metadata
        $buildMetadata = $this->retrieveBuildMetadata("{$site_name}.dev");
        $url = $this->getMetadataUrl($buildMetadata);

        // Create a git repository service provider appropriate to the URL
        $this->git_provider = $this->inferGitProviderFromUrl($url);

        // Extract just the project id from the URL
        $target_project = $this->projectFromRemoteUrl($url);
        $this->git_provider->getEnvironment()->setProjectId($target_project);

        $ci_provider_class_or_alias = $this->selectCIProvider($this->git_provider->getServiceName(), $input->getOption('ci'));

        $this->createCIProvider($ci_provider_class_or_alias);
        $this->createSiteProvider('pantheon');

        // Flush the cache so that when we ask, it will re-prompt for all
        // credentials.
        // n.b. credentials will stil be taken from environment variables, if set.
        $this->providerManager()->credentialManager()->clearAll();

        // Copy the site name into the 'pantheon-site' option.
        $input->setOption('pantheon-site', $site_name);

        // Copy the options into the credentials cache as appropriate
        $this->providerManager()->setFromOptions($input);
    }

    /**
     * Ensure that the user has provided credentials for GitHub and Circle CI,
     * and prompt for them if they have not.
     *
     * n.b. This hook is not called in --no-interaction mode.
     *
     * @hook interact build:project:repair
     */
    public function interact(InputInterface $input, OutputInterface $output, AnnotationData $annotationData)
    {
        $io = new SymfonyStyle($input, $output);
        $this->providerManager()->credentialManager()->ask($io);
    }

    /**
     * Ensure that the user has not supplied any parameters with invalid values.
     *
     * @hook validate build:project:repair
     */
    public function validateCreateProject(CommandData $commandData)
    {
        $this->log()->notice('build:project:repair @hook validate');

        // Ensure that all of our providers are given the credentials they requested.
        $this->providerManager()->validateCredentials();
    }

    /**
     * Re-apply credentials to a site created by the build:project:create command.
     *
     * @command build:project:repair
     */
    public function repair(
        $site_name,
        $options = [
            'email' => '',
            'pantheon-site' => '',
            'test-site-name' => '',
            'admin-password' => '',
            'admin-email' => '',
            'admin-username' => '',
            'env' => [],
            'ci' => '',
            'clu-cron-pattern' => '',
        ])
    {
        // Get the environment variables to be stored in the CI server.
        $ci_env = $this->getCIEnvironment($options['env']);

        if (!empty($options['clu-cron-pattern'])) {
            $clu_env = new ProviderEnvironment();
            $clu_env['cron_pattern'] = $options['clu-cron-pattern'];
            $ci_env->storeState('clu', $clu_env);
        }

        // Add the environment variables from the git provider to the CI environment.
        $ci_env->storeState('repository', $this->git_provider->getEnvironment());

        // Add the environment variables from the site provider to the CI environment.
        $ci_env->storeState('site', $this->site_provider->getEnvironment());

        // Determine if the site has multidev capability
        $site = $this->getSite($site_name);
        $hasMultidevCapability = $this->siteHasMultidevCapability($site);

        // Do the work
        $builder = $this->collectionBuilder();

        $builder
            // Set up CI to test our project.
            ->taskCISetup()
                ->provider($this->ci_provider)
                ->environment($ci_env)
                ->hasMultidevCapability($hasMultidevCapability)

            // Create public and private key pair and add them to any provider
            // that requested them.
            ->taskCreateKeys()
                ->environment($ci_env)
                ->provider($this->ci_provider)
                ->provider($this->git_provider)
                ->provider($this->site_provider)

            // Tell the CI service to start testing
            ->taskCIStartTesting()
                ->provider($this->ci_provider)
                ->environment($ci_env)

            ->addCode(
                function ($state) use ($site_name) {
                  $secretValues = $this->git_provider->getSecretValues();
                  $this->writeSecrets("{$site_name}.dev", $secretValues, false, 'tokens.json');
                }
            );

        return $builder;
    }
}
