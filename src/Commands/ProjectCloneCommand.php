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
use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CIState;
use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;
use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\RepositoryEnvironment;
use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CircleCIProvider;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Project Create Command
 */
class ProjectCloneCommand extends BuildToolsBase
{
    use \Pantheon\TerminusBuildTools\Task\Ssh\Tasks;
    use \Pantheon\TerminusBuildTools\Task\CI\Tasks;
    use \Pantheon\TerminusBuildTools\Task\Quicksilver\Tasks;

    /**
     * Initialize the default value for selected options.
     * Validate requested site name before prompting for additional information.
     *
     * @hook init build:project:clone
     */
    public function initOptionValues(InputInterface $input, AnnotationData $annotationData)
    {
        $git_provider_class_or_alias = $input->getOption('git');
        $source = $input->getArgument('source');
        $target = $input->getArgument('target');
        $target_org = $input->getOption('org');

        // Create the providers via the provider manager.
        // Each provider that is created is also registered, so
        // when we call `credentialManager()->ask()` et. al.,
        // each will be called in turn.
        $this->createProviders(
            $git_provider_class_or_alias,
            '',
            ''
        );

        // $this->createGitProvider($git_provider_class_or_alias);

        // If the source site is a common alias, then replace it with its expanded value
        $source = $this->expandSourceAliases($source);

        // If an org was provided for the target, then extract it into
        // the `$org` variable
        if (strpos($target, '/') !== FALSE) {
            list($target_org, $target) = explode('/', $target, 2);
        }

        // Assign variables back to $input after filling in defaults.
        $input->setArgument('source', $source);
        $input->setArgument('target', $target);
        $input->setOption('org', $target_org);

        // Copy the options into the credentials cache as appropriate
        $this->providerManager()->setFromOptions($input);
    }

    /**
     * Ensure that the user has provided credentials,
     * and prompt for them if they have not.
     *
     * n.b. This hook is not called in --no-interaction mode.
     *
     * @hook interact build:project:clone
     */
    public function interact(InputInterface $input, OutputInterface $output, AnnotationData $annotationData)
    {
        $io = new SymfonyStyle($input, $output);
        $this->providerManager()->credentialManager()->ask($io);
    }

    /**
     * Ensure that the user has not supplied any parameters with invalid values.
     *
     * @hook validate build:project:clone
     */
    public function validateCreateProject(CommandData $commandData)
    {
        // Ensure that all of our providers are given the credentials they requested.
        $this->providerManager()->validateCredentials();
    }

    /**
     * Create a new project from the requested source project.
     * In order to use this command, it is also necessary to provide
     * a set of secrets that are used to create the necessary project.
     * Currently, these secrets should be provided via
     * environment variables; this keeps them out of the command history
     * and other places they may be inadvertantly observed.
     *
     * GitHub configuration:
     *   export GITHUB_TOKEN=github_personal_access_token
     *
     * BitBucket configuration:
     *   export BITBUCKET_USER=bitbucket_username
     *   export BITBUCKET_PASS=bitbucket_account_or_app_password
     *
     * GitLab configuration:
     *   export GITLAB_TOKEN=gitlab_personal_access_token
     *
     * Secrets that are not exported will be prompted.
     *
     * @authorize
     *
     * @command build:project:clone
     * @param string $source org/name of source template project to clone.
     * @param string $target Machine-friendly name of project to create (kebab-case).
     * @option org Organization for the new project (defaults to authenticated user)
     * @option visibility The desired visibility of the provider repository. Options are public, internal, and private.
     * @option type The project type. Options are git, npm, gatsby and composer.
     */
    public function cloneProject(
        $source,
        $target = '',
        $options = [
            'org' => '',
            'env' => [],
            'preserve-local-repository' => false,
            'keep' => false,
            'git' => 'github',
            'visibility' => 'public',
            'type' => 'git',
        ])
    {
        $this->warnAboutOldPhp();

        // Copy options into ordinary variables
        $target_org = $options['org'];
        $visibility = $options['visibility'];

        if (empty($source)) {
            throw new TerminusException('A source project is required.', compact('source'));
        }
        
        if (empty($target)) {
            throw new TerminusException('A target project name is required.', compact('target'));
        }

        // This target label is only used for the log messages below.
        $target_label = $target;
        if (!empty($target_org)) {
            $target_label = "$target_org/$target";
        }

        $repositoryAttributes = $this->git_provider->getEnvironment();

        // Pull down the source project
        $this->log()->notice('Create a local working copy of {src}', ['src' => $source]);

        if( false !== stripos($source, 'gatsby') ) {
            $options['type'] = 'gatsby';
        }
        
        $siteDir = $this->createFromSource($source, $target, $stability='', $options);

        $builder = $this->collectionBuilder();

        $builder

            // Create a repository
            ->progressMessage('Create Git repository {target}', ['target' => $target_label])
            ->addCode(
                function ($state) use ($repositoryAttributes, $target, $target_org, $siteDir, $visibility) {

                    $target_project = $this->git_provider->createRepository($siteDir, $target, $target_org, $visibility);

                    $this->log()->notice('The target is {target}', ['target' => $target_project]);
                    $repositoryAttributes->setProjectId($target_project);
                    
                    $this->log()->notice('Contents of {site_dir} are:', ['site_dir' => $siteDir]);
                    $this->passthru("ls -a $siteDir");
                })

            // Create new repository and make the initial commit
            ->progressMessage('Make initial commit')
            ->addCode(
                function ($state) use ($siteDir, $source) {
                    $headCommit = $this->initialCommit($siteDir, $source);
                });
            
        // If the project type is Composer, run build-assets
        if ( 'composer' === $options['type'] ) {
            $builder
                // Add a task to run the 'build assets' step, if possible. Do nothing if it does not exist.
                ->progressMessage('Build assets for {target}', ['target' => $target])
                ->addCode(
                    function ($state) use ($siteDir, $source) {
                        $this->log()->notice('Determine whether build-assets exists for {source}', ['source' => $source]);
                        exec("composer --working-dir=$siteDir help build-assets", $outputLines, $status);
                        if (!$status) {
                            $this->log()->notice('Building assets for {target}', ['target' => $target]);
                            $this->passthru("composer --working-dir=$siteDir build-assets");
                        }
                    }
                );
        }

        $builder
            // Push the local working repository to the server
            ->progressMessage('Push initial code to {target}', ['target' => $target_label])
            ->addCode(
                function ($state) use ($repositoryAttributes, $siteDir) {
                    $this->git_provider->pushRepository($siteDir, $repositoryAttributes->projectId());
                }
            );
        
            // If the user specified --keep, then clone a local copy of the project
        if ($options['keep']) {
            $builder
                ->addCode(
                    function ($state) use ($siteDir) {
                        $keepDir = basename($siteDir);
                        $fs = new Filesystem();
                        $fs->mirror($siteDir, $keepDir);
                        $this->log()->notice('Keeping a local copy of new project at {dir}', ['dir' => $keepDir]);
                    }
                );
        }

        // Give a final status message with the project URL
        $builder->addCode(
            function ($state) use ($repositoryAttributes) {
                $target_project = $repositoryAttributes->projectId();
                $this->log()->notice('Success! Visit your new project at {url}', ['url' => $this->git_provider->projectURL($target_project)]);
            });

        // If we return the builder, Robo will run it. This also allows
        // command hooks to alter the task collection prior to execution.
        return $builder;
    }

    /**
     * Make the initial commit to our new project.
     */
    protected function initialCommit($repositoryDir, $source)
    {
        // Add the canonical repository files to the new GitHub project
        // respecting .gitignore.
        $this->passthru("git -C $repositoryDir add .");
        $this->passthru("git -C $repositoryDir commit -m 'Create new clone from $source'");
        return $this->getHeadCommit($repositoryDir);
    }

}
