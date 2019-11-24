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
        if ($source_provider = $this->providerManager()->inferProvider($source, GitProvider::class)) {
            $this->conversion_type = self::CONVERSION_TYPE_PROVIDER;
            $this->git_provider = $source_provider;
        }
        elseif ($source_provider = $this->providerManager()->inferProvider($source, SiteProvider::class)) {
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
        $input->setOption('pantheon-site', $site_name);
        // Copy the options into the credentials cache as appropriate
        $this->providerManager()->setFromOptions($input);
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
                function ($state) use ($ci_env, $target, $target_org, $siteDir, $visibility) {

                    $target_project = $this->git_provider->createRepository($siteDir, $target, $target_org, $visibility);

                    $repositoryAttributes = $ci_env->getState('repository');

                    $this->log()->notice('The target is {target}', ['target' => $target_project]);
                    $repositoryAttributes->setProjectId($target_project);
                });
        }
        else {
            // We need to create the Pantheon site.
            $builder

              ->progressMessage('Create Pantheon site {site}', ['site' => $target])
              ->addCode(
                function ($state) use ($target, $label, $team, $siteDir, $region) {
                    // Look up our upstream.
                    $upstream = $this->autodetectUpstream($siteDir);

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

                    file_put_contents("$siteDir/README.md", $readme);

                    // If this site cannot create multidev environments, then configure
                    // it to always run tests on the dev environment.
                    $state['has-multidev-capability'] = $this->siteHasMultidevCapability($site);
                });
        }

//        // Now that we have our sites created, we need to composerize them.
//        // First, clone locally
        $builder

            ->progressMessage('Composerize website')
            ->addCode(
              function ($state) use ($target, $siteDir) {
                  $this->composerizeSite($siteDir);
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

          /*
          ->taskRepositoryPush()
              ->provider($this->git_provider)
              ->target($this->target_project)
              ->dir($siteDir)
          */
          // Add a task to run the 'build assets' step, if possible. Do nothing if it does not exist.
          ->progressMessage('Build assets for {site}', ['site' => $site_name])
          ->addCode(
            function ($state) use ($siteDir, $source, $site_name) {
                $this->log()->notice('Determine whether build-assets exists for {source}', ['source' => $source]);
                exec("composer --working-dir=$siteDir help build-assets", $outputLines, $status);
                if (!$status) {
                    $this->log()->notice('Building assets for {site}', ['site' => $site_name]);
                    $this->passthru("composer --working-dir=$siteDir build-assets");
                }
            }
          )

          // Push code to newly-created project.
          // Note that this also effectively does a 'git reset --hard'
          ->progressMessage('Push code to Pantheon site {site}', ['site' => $site_name])
          ->addCode(
            function ($state) use ($site_name, $siteDir) {
                $this->pushCodeToPantheon("{$site_name}.dev", 'dev', $siteDir);
                // Remove the commit added by pushCodeToPantheon; we don't need the build assets locally any longer.
                $this->resetToCommit($siteDir, $state['initial_commit']);
            })

          // Install our site and export configuration.
          // Note that this also commits the configuration to the repository.
          ->progressMessage('Install CMS on Pantheon site {site}', ['site' => $site_name])
          ->addCode(
            function ($state) use ($ci_env, $site_name, $siteDir) {
                $siteAttributes = $ci_env->getState('site');
                $composer_json = $this->getComposerJson($siteDir);

                // Install the site.
                $site_install_options = [
                  'account-mail' => $siteAttributes->adminEmail(),
                  'account-name' => 'admin',
                  'account-pass' => $siteAttributes->adminPassword(),
                  'site-mail' => $siteAttributes->adminEmail(),
                  'site-name' => $siteAttributes->testSiteName(),
                  'site-url' => "https://dev-{$site_name}.pantheonsite.io"
                ];
                $this->doInstallSite("{$site_name}.dev", $composer_json, $site_install_options);

                // Before any tests have been configured, export the
                // configuration set up by the installer.
                $this->exportInitialConfiguration("{$site_name}.dev", $siteDir, $composer_json, $site_install_options);
            })

          // Push the local working repository to the server
          ->progressMessage('Push initial code to {target}', ['target' => $label])
          /*
          ->taskRepositoryPush()
              ->provider($this->git_provider)
              ->target($this->target_project)
              ->dir($siteDir)
          */
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
        $this->passthru("cd $siteDir");
        $upstream = $this->autodetectUpstream($siteDir);
        if ($upstream == "empty-wordpress") {
            $command = "composerize-wordpress";
        }
        else {
            $command = "composerize-drupal";
        }
        $this->passthru("$command");
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