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
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\GitProvider;
use Pantheon\TerminusBuildTools\ServiceProviders\SiteProviders\SiteProvider;
use Pantheon\TerminusBuildTools\Utility\UrlParsing;
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
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Project Convert Command
 */
class ProjectConvertCommand extends BuildToolsBase {

    use \Pantheon\TerminusBuildTools\Task\Ssh\Tasks;
    use \Pantheon\TerminusBuildTools\Task\CI\Tasks;
    use \Pantheon\TerminusBuildTools\Task\Quicksilver\Tasks;

    const CONVERSION_TYPE_PROVIDER = "PROVIDER";
    const CONVERSION_TYPE_PANTHEON = "PANTHEON";

    private $conversion_type;
    private $pantheon_site_uuid;

    /**
     * Initialize the default value for selected options.
     * Validate requested site name before prompting for additional information.
     *
     * @hook init build:project:convert
     */
    public function initOptionValues(InputInterface $input, AnnotationData $annotationData)
    {
        $git_provider_class_or_alias = $input->getOption('git');
        $target_org = $input->getOption('org');
        $site_name = $input->getOption('pantheon-site');
        $source = $input->getArgument('source');

        // First things first, we need to figure out if we have a Pantheon site or a Git site.
        // ssh://codeserver.dev.4c378f81-a3d6-407b-a116-c4b1b0c4c4a9@codeserver.dev.4c378f81-a3d6-407b-a116-c4b1b0c4c4a9.drush.in:2222/~/repository.git
        if ($source_provider = $this->providerManager()->inferProvider($source, GitProvider::class, false)) {
            $this->conversion_type = self::CONVERSION_TYPE_PROVIDER;
            $this->git_provider = $this->providerManager()->inferProvider($source, GitProvider::class);
        }
        elseif ($source_provider = $this->providerManager()->inferProvider($source, SiteProvider::class, false)) {
            $this->conversion_type = self::CONVERSION_TYPE_PANTHEON;
        }
        else {
            throw new TerminusException("Unable to determine source repository provider. Please check your GIT URL.");
        }

        // Validate that appropriate options were set depending on the conversion type.
        if ($this->conversion_type == self::CONVERSION_TYPE_PANTHEON) {
            if (empty($git_provider_class_or_alias)) {
                throw new TerminusException("Attempting to convert an existing Pantheon website requires specifying a Git provider to use with the new site.");
            }
            // Determine site's UUID so we can look it up later.
            $repo_url_parts = explode('.', $source);
            $this->pantheon_site_uuid = str_replace('@codeserver', '', $repo_url_parts[2]);
            $pantheon_site = $this->sites()->get($this->pantheon_site_uuid);
            $site_name = $pantheon_site->getName();
        }
        else {
            if (empty($site_name)) {
                $site_name = UrlParsing::orgUserFromRemoteUrl($source) . '_' . UrlParsing::repositoryFromRemoteUrl($source);
            }
            // Before we begin, check to see if the requested site name is
            // available on Pantheon, and fail if it is not.
            $site_name = strtr(strtolower($site_name), '_ ', '--');
            if ($this->sites()->nameIsTaken($site_name)) {
                throw new TerminusException('The site name {site_name} is already taken on Pantheon.', compact('site_name'));
            }
        }

        if ($this->conversion_type == self::CONVERSION_TYPE_PROVIDER) {
            $ci_provider_class_or_alias = $this->selectCIProvider($this->git_provider->getServiceName(), $input->getOption('ci'));
        }
        else {
            $ci_provider_class_or_alias = $this->selectCIProvider($git_provider_class_or_alias, $input->getOption('ci'));
        }

        // Create the providers via the provider manager.
        // Each provider that is created is also registered, so
        // when we call `credentialManager()->ask()` et. al.,
        // each will be called in turn.
        $this->createProviders(
          $git_provider_class_or_alias,
          $ci_provider_class_or_alias,
          'pantheon'
        );

        // Assign variables back to $input after filling in defaults.
        $input->setArgument('source', $source);
        $input->setOption('org', $target_org);
        $input->setOption('pantheon-site', $site_name);
        $input->setOption('ci', $ci_provider_class_or_alias);
        // Copy the options into the credentials cache as appropriate
        $this->providerManager()->setFromOptions($input);
    }

    /**
     * Ensure that the user has provided credentials for GitHub and Circle CI,
     * and prompt for them if they have not.
     *
     * n.b. This hook is not called in --no-interaction mode.
     *
     * @hook interact build:project:convert
     */
    public function interact(InputInterface $input, OutputInterface $output, AnnotationData $annotationData)
    {
        $io = new SymfonyStyle($input, $output);
        $this->providerManager()->credentialManager()->ask($io);
    }


    /**
     * Ensure that the user has not supplied any parameters with invalid values.
     *
     * @hook validate build:project:convert
     */
    public function validateConvertProject(CommandData $commandData)
    {
        // Ensure that all of our providers are given the credentials they requested.
        $this->providerManager()->validateCredentials();
    }

    /**
     * Converts a project to the Terminus BT CI workflow.
     *
     * @authorize
     *
     * @command build:project:convert
     * @param string $source The Git URL of the existing website.
     */
    public function convertProject($source, $options = [
            'org' => '',
            'team' => null,
            'label' => '',
            'email' => '',
            'pantheon-site' => '',
            'test-site-name' => '',
            'admin-password' => '',
            'admin-email' => '',
            'stability' => '',
            'env' => [],
            'preserve-local-repository' => false,
            'keep' => false,
            'ci' => '',
            'git' => '',
            'visibility' => 'public',
            'region' => '',
        ]) {
        $this->warnAboutOldPhp();

        // Copy options into ordinary variables.
        $target_org = $options['org'];
        $target = $options['pantheon-site'];
        $site_name = $options['pantheon-site'];
        $team = $options['team'];
        $label = $options['label'];
        $stability = $options['stability'];
        $visibility = $options['visibility'];
        $region = $options['region'];

        // Get the environment variables to be stored in the CI server.
        $ci_env = $this->getCIEnvironment($options['env']);

        // Add the environment variables from the git provider to the CI environment.
        $ci_env->storeState('repository', $this->git_provider->getEnvironment());

        // Add the environment variables from the site provider to the CI environment.
        $ci_env->storeState('site', $this->site_provider->getEnvironment());

        // Give ourselves a temp directory.
        $siteDir = $this->tempdir('local-site');

        $builder = $this->collectionBuilder();

        $framework = "";

        // Do we have a Git Provider? If not, create one.
        if ($this->conversion_type == self::CONVERSION_TYPE_PANTHEON) {
            // Determine if the site has multidev capability
            /** @var \Pantheon\Terminus\Models\Site $site */
            $site = $this->getSite($this->pantheon_site_uuid);
            if (empty($target)) {
                $target = $site->getName();
            }
            $builder
              // Create a repository
              ->progressMessage('Create Git repository {target}', ['target' => $target])
              ->addCode(
                function ($state) use ($ci_env, $target, $target_org, $siteDir, $visibility, $site) {

                    $target_project = $this->git_provider->createRepository($siteDir, $target, $target_org, $visibility);

                    $repositoryAttributes = $ci_env->getState('repository');

                    $this->log()->notice('The target is {target}', ['target' => $target_project]);
                    $repositoryAttributes->setProjectId($target_project);

                    $state['has-multidev-capability'] = $this->siteHasMultidevCapability($site);
                })
                ->progressMessage('Adding existing Pantheon site as remote')
                ->addCode(
                  function ($state) use ($siteDir, $target, $site) {
                      $dev_env = $site->getEnvironments()->get('dev');
                      $connectionInfo = $dev_env->connectionInfo();
                      $gitUrl = $connectionInfo['git_url'];
                      $this->passthru("git -C $siteDir remote add pantheon $gitUrl");
                      $this->passthru("git -C $siteDir pull pantheon master");
                      $this->passthru("ls");
                  }
                );
        }
        else {
            // Pull the site locally.
            $builder
              ->progressMessage('Cloning site for local development.')
              ->addCode(
                function ($state) use ($siteDir, $source) {
                    $this->passthru("git -C $siteDir clone $source .");
                }
              );

            // We need to create the Pantheon site.
            $builder
              ->progressMessage('Create Pantheon site {site}', ['site' => $target])
              ->addCode(
                function ($state) use ($target, $label, $team, $siteDir, $region, &$framework) {
                    // Look up our upstream.
                    $applicationInfo = $this->autodetectApplication($siteDir);
                    $upstream = $applicationInfo['framework'];
                    $framework = $applicationInfo['application'];

                    $this->log()->notice('About to create Pantheon site {site} in {team} with upstream {upstream}', ['site' => $target, 'team' => $team, 'upstream' => $upstream]);

                    $site = $this->siteCreate($target, $label, $upstream, ['org' => $team, 'region' => $region]);

                    $siteInfo = $site->serialize();
                    $site_uuid = $siteInfo['id'];

                    $this->log()->notice('Created a new Pantheon site with UUID {uuid}', ['uuid' => $site_uuid]);

                    // Create a new README file to point to the Pantheon dashboard and dev site.
                    // Put in a placeholder for the CI badge to be inserted into later.
                    $ciPlaceholder = "![CI none](https://img.shields.io/badge/ci-none-orange.svg)";
                    $badgeTargetLabel = strtr($target, '-', '_');
                    $pantheonBadge = "[![Dashboard {$target}](https://img.shields.io/badge/dashboard-{$badgeTargetLabel}-yellow.svg)](https://dashboard.pantheon.io/sites/{$site_uuid}#dev/code)";
                    $siteBadge = "[![Dev Site {$target}](https://img.shields.io/badge/site-{$badgeTargetLabel}-blue.svg)](http://dev-{$target}.pantheonsite.io/)";
                    $readme = "# $target\n\n$ciPlaceholder\n$pantheonBadge\n$siteBadge";

                    if (file_exists("$siteDir/README.md")) {
                        file_put_contents("$siteDir/README.md", $readme, FILE_APPEND);
                    }
                    else {
                        file_put_contents("$siteDir/README.md", $readme);
                    }


                    // If this site cannot create multidev environments, then configure
                    // it to always run tests on the dev environment.
                    $state['has-multidev-capability'] = $this->siteHasMultidevCapability($site);
                });
        }

      $builder
        ->progressMessage('Update composerize tool to use Pantheon template files.')
        ->addCode(
          function ($state) use ($framework) {
              if ($framework == "Drupal") {
                  $home_dir = $this->exec("composer global config home -q");
                  $home_dir = $home_dir[0];
                  if (!file_exists($home_dir . '/vendor/grasmash/composerize-drupal/template.composer.json')) {
                      throw new TerminusException("Composerize Drupal does not appear to be installed.");
                  }
                  $this->passthru("wget https://raw.githubusercontent.com/pantheon-systems/example-drops-8-composer/master/composer.json -O {$home_dir}/vendor/grasmash/composerize-drupal/template.composer.json");
                  $composer_json = json_decode(file_get_contents($home_dir . '/vendor/grasmash/composerize-drupal/template.composer.json'));
                  $merge_object = new \stdClass();
                  $merge_object = [
                    'include' => [
                      'web/modules/custom/*/composer.json',
                    ],
                    'replace' => FALSE,
                    'ignore-duplicates' => FALSE,
                  ];
                  $composer_json->extra->{"merge-plugin"} = $merge_object;
                  file_put_contents($home_dir . '/vendor/grasmash/composerize-drupal/template.composer.json', json_encode($composer_json, JSON_PRETTY_PRINT));
              }
          }
        );

        // Github doesn't allow us to pull down directories via git archive so we abuse svn instead.
        $builder
          ->progressMessage("Download required files from example repository")
          ->addCode(
            function ($state) use ($siteDir, $framework) {
                if ($framework == "Drupal") {
                    $this->passthru("cd $siteDir && svn checkout https://github.com/pantheon-systems/example-drops-8-composer/trunk/scripts");
                    $this->passthru("cd $siteDir && svn checkout https://github.com/pantheon-systems/example-drops-8-composer/trunk/tests");
                    $this->passthru("cd $siteDir && svn checkout https://github.com/pantheon-systems/example-drops-8-composer/trunk/.ci");
                    $this->passthru("cd $siteDir && svn checkout https://github.com/pantheon-systems/example-drops-8-composer/trunk/.circleci");
                    $this->passthru("wget https://raw.githubusercontent.com/pantheon-systems/example-drops-8-composer/master/.gitlab-ci.yml -O {$siteDir}/.gitlab-ci.yml");
                    $this->passthru("wget https://raw.githubusercontent.com/pantheon-systems/example-drops-8-composer/master/bitbucket-pipelines.yml -O {$siteDir}/.bitbucket-pipelines.yml");
                    // We manually set the gitignore here because the composerize commands try to merge and we want to replace.
                    $this->passthru("wget https://raw.githubusercontent.com/pantheon-systems/example-drops-8-composer/master/.gitignore -O {$siteDir}/.gitignore");
                }
                else {
                    $this->passthru("cd $siteDir && svn checkout https://github.com/pantheon-systems/example-wordpress-composer/trunk/scripts");
                    $this->passthru("cd $siteDir && svn checkout https://github.com/pantheon-systems/example-wordpress-composer/trunk/tests");
                    $this->passthru("cd $siteDir && svn checkout https://github.com/pantheon-systems/example-wordpress-composer/trunk/.ci");
                    $this->passthru("cd $siteDir && svn checkout https://github.com/pantheon-systems/example-wordpress-composer/trunk/.circleci");
                    $this->passthru("wget https://raw.githubusercontent.com/pantheon-systems/example-wordpress-composer/master/.gitlab-ci.yml -O {$siteDir}/.gitlab-ci.yml");
                    $this->passthru("wget https://raw.githubusercontent.com/pantheon-systems/example-wordpress-composer/master/bitbucket-pipelines.yml -O {$siteDir}/.bitbucket-pipelines.yml");
                    // We manually set the gitignore here because the composerize commands try to merge and we want to replace.
                    $this->passthru("wget https://raw.githubusercontent.com/pantheon-systems/example-wordpress-composer/master/.gitignore -O {$siteDir}/.gitignore");

                }

            }
          );

        // Now that we have our sites created, we need to composerize them.
        $builder
          ->progressMessage('Composerize website')
          ->addCode(
            function ($state) use ($target, $siteDir) {
              $this->composerizeSite($siteDir);
            }
          );

        // Move custom items.
        $builder
          ->progressMessage("Moving custom plugins, modules, and themes.")
          ->addCode(
            function ($state) use ($siteDir, $framework) {
              $this->passthru("ls $siteDir");
              if ($framework == "Drupal") {
                if (is_dir($siteDir . '/modules/custom')) {
                  $this->passthru("mv {$siteDir}/modules/custom {$siteDir}/web/modules/custom");
                }
                if (is_dir($siteDir . '/themes/custom')) {
                  $this->passthru("mv {$siteDir}/themes/custom {$siteDir}/web/themes/custom");
                }
              }
              else {
                if (is_dir($siteDir . '/wp-content/plugins')) {
                  $this->passthru("mv {$siteDir}/wp-content/plugins {$siteDir}/web/wp-content/plugins");
                }
                if (is_dir($siteDir . '/wp-content/themes')) {
                  $this->passthru("mv {$siteDir}/wp-content/themes {$siteDir}/web/wp-content/themes");
                }
              }
            }
          );


        // Remove legacy files/directories
        $builder
            ->progressMessage("Removing legacy site artifacts.")
            ->addCode(
              function ($state) use ($siteDir, $framework) {
                $drupal_directories = [
                  $siteDir . '/core',
                  $siteDir . '/libraries',
                  $siteDir . '/modules',
                  $siteDir . '/profiles',
                  $siteDir . '/sites',
                  $siteDir . '/themes',
                ];
                $wordpress_directories = [
                  $siteDir . '/wp-content',
                  $siteDir . '/wp-includes',
                  $siteDir . '/wp-admin',
                ];

                if ($framework == "Drupal") {
                  $directories = $drupal_directories;
                }
                else {
                  $directories = $wordpress_directories;
                }
                foreach ($directories as $directory) {
                  $this->passthru("rm -rf $directory");
                }
              }
            );

        $builder

          ->progressMessage('Make initial commit')
          ->addCode(
            function ($state) use ($siteDir, $source) {
                $headCommit = $this->commitChanges($siteDir, $source);
            })

          ->progressMessage('Set up CI services')

          // Set up CI to test our project.
          // Note that this also modifies the README and commits it to the repository.
          ->taskCISetup()
          ->provider($this->ci_provider)
          ->environment($ci_env)
          ->deferTaskConfiguration('hasMultidevCapability', 'has-multidev-capability')
          ->dir($siteDir)

          // Create public and private key pair and add them to any provider
          // that requested them. Providers that implement PrivateKeyReceiver,
          // PublicKeyReceiver or KeyPairReceiver will be called with the
          // private key, the public key, or both.
          ->taskCreateKeys()
          ->environment($ci_env)
          ->provider($this->ci_provider)
          ->provider($this->git_provider)
          ->provider($this->site_provider)

          ->progressMessage('Initialize build-providers.json')
          ->taskPushbackSetup()
          ->dir($siteDir)
          ->provider($this->git_provider, $this->ci_provider)
          ->progressmessage('Set build secrets')
          ->addCode(
            function ($state) use ($site_name, $siteDir) {
              $secretValues = $this->git_provider->getSecretValues();
              $this->writeSecrets("{$site_name}.dev", $secretValues, false, 'tokens.json');
              // Remember the initial commit sha
              $state['initial_commit'] = $this->getHeadCommit($siteDir);
            }
          )

          // Add a task to run the 'build assets' step, if possible. Do nothing if it does not exist.
          ->progressMessage('Build assets for {site}', ['site' => $label])
          ->addCode(
            function ($state) use ($siteDir, $source, $label) {
              $this->log()->notice('Determine whether build-assets exists for {source}', ['source' => $source]);
              exec("composer --working-dir=$siteDir help build-assets", $outputLines, $status);
              if (!$status) {
                $this->log()->notice('Building assets for {site}', ['site' => $label]);
                $this->passthru("composer --working-dir=$siteDir build-assets");
              }
            }
          )

          // Push code to newly-created project.
          // Note that this also effectively does a 'git reset --hard'
          ->progressMessage('Push code to Pantheon site {site}', ['site' => $label])
          ->addCode(
            function ($state) use ($site_name, $siteDir) {
              $this->pushCodeToPantheon("{$site_name}.dev", 'dev', $siteDir);
              // Remove the commit added by pushCodeToPantheon; we don't need the build assets locally any longer.
              $this->resetToCommit($siteDir, $state['initial_commit']);
            })

          // Push the local working repository to the server
          ->progressMessage('Push initial code to {target}', ['target' => $label])
          ->addCode(
            function ($state) use ($ci_env, $siteDir) {
                $repositoryAttributes = $ci_env->getState('repository');
                $this->git_provider->pushRepository($siteDir, $repositoryAttributes->projectId());
            })

          // Tell the CI server to start testing our project
          ->progressMessage('Beginning CI testing')
          ->taskCIStartTesting()
          ->provider($this->ci_provider)
          ->environment($ci_env);


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
          function ($state) use ($ci_env) {
            $repositoryAttributes = $ci_env->getState('repository');
            $target_project = $repositoryAttributes->projectId();
            $this->log()->notice('Success! Visit your new site at {url}', ['url' => $this->git_provider->projectURL($target_project)]);
          });

        return $builder;
    }

    private function composerizeSite($siteDir) {
        $application = $this->autodetectApplicationName($siteDir);
        if ($application == "WordPress") {
          $command = "composerize-wordpress";
        }
        else {
          $command = "composerize-drupal";
        }
        $this->passthru("cd $siteDir && composer $command");
    }

    /**
     * Make the initial commit to our new project.
     */
    protected function commitChanges($repositoryDir, $source)
    {
        // Add the canonical repository files to the new GitHub project
        // respecting .gitignore.
        $this->passthru("git -C $repositoryDir add .");
        $this->passthru("git -C $repositoryDir commit -m 'Convert site to be composer managed'");
        return $this->getHeadCommit($repositoryDir);
    }

}