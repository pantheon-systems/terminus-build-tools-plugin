<?php
/**
 * Terminus Plugin that contain a collection of commands useful during
 * the build step on a [Pantheon](https://www.pantheon.io) site that uses
 * a GitHub PR workflow.
 *
 * See README.md for usage information.
 */

namespace Pantheon\TerminusBuildTools\Commands;

use Pantheon\Terminus\Exceptions\TerminusException;
use Symfony\Component\Filesystem\Filesystem;
use Consolidation\AnnotatedCommand\AnnotationData;
use Consolidation\AnnotatedCommand\CommandData;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Pantheon\TerminusBuildTools\Utility\Config as Config_Utility;
use Symfony\Component\Console\Style\SymfonyStyle;
use VersionTool\VersionTool;
use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;

/**
 * Project Create Command
 */
class ProjectCreateCommand extends BuildToolsBase
{
    use \Pantheon\TerminusBuildTools\Task\Ssh\Tasks;
    use \Pantheon\TerminusBuildTools\Task\CI\Tasks;
    use \Pantheon\TerminusBuildTools\Task\Quicksilver\Tasks;

    /**
     * Initialize the default value for selected options.
     * Validate requested site name before prompting for additional information.
     *
     * @hook init build:project:create
     */
    public function initOptionValues(InputInterface $input, AnnotationData $annotationData)
    {
        $git_provider_class_or_alias = $input->getOption('git');
        $target_org = $input->getOption('org');
        $site_name = $input->getOption('pantheon-site');
        $source = $input->getArgument('source');
        $target = $input->getArgument('target');

        $ci_provider_class_or_alias = $this->selectCIProvider($git_provider_class_or_alias, $input->getOption('ci'));

        // Create the providers via the provider manager.
        // Each provider that is created is also registered, so
        // when we call `credentialManager()->ask()` et. al.,
        // each will be called in turn.
        $this->createProviders(
            $git_provider_class_or_alias,
            $ci_provider_class_or_alias,
            'pantheon'
        );

        // If only one parameter was provided, then it is the TARGET
        if (empty($target)) {
            $target = $source;
            $source = 'd8';
        }

        // If the source site is a common alias, then replace it with its expanded value
        $source = $this->expandSourceAliases($source);

        // If an org was not provided for the source, then assume pantheon-systems
        if (preg_match('#^[a-zA-Z0-9_-]*$#', $source)) {
            $source = "pantheon-systems/$source";
        }

        // If an org was provided for the target, then extract it into
        // the `$org` variable
        if (strpos($target, '/') !== FALSE) {
            list($target_org, $target) = explode('/', $target, 2);
        }

        // If the user did not explicitly provide a Pantheon site name,
        // then use the target name for that purpose. This will probably
        // be the most common usage -- with matching GitHub / Pantheon
        // site names.
        if (empty($site_name)) {
            $site_name = $target;
        }

        // Before we begin, check to see if the requested site name is
        // available on Pantheon, and fail if it is not.
        $site_name = strtr(strtolower($site_name), '_ ', '--');
        if ($this->sites()->nameIsTaken($site_name)) {
            throw new TerminusException('The site name {site_name} is already taken on Pantheon.', compact('site_name'));
        }

        // Assign variables back to $input after filling in defaults.
        $input->setArgument('source', $source);
        $input->setArgument('target', $target);
        $input->setOption('org', $target_org);
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
     * @hook interact build:project:create
     */
    public function interact(InputInterface $input, OutputInterface $output, AnnotationData $annotationData)
    {
        $io = new SymfonyStyle($input, $output);
        $this->providerManager()->credentialManager()->ask($io);

        // Encourage the user to select a team
        $team = $input->getOption('team');
        if (empty($team)) {
            $team = getenv('TERMINUS_TEAM');
        }
        if (empty($team)) {
            $orgs = array_values($this->availableOrgs());
            if (!empty($orgs)) {
                array_unshift($orgs, '-');
                $team = $this->io()->choice('Select a team for this site', $orgs);
            }
        }
        if ($team != '-') {
            $input->setOption('team', $team);
        }
    }

    /**
     * Ensure that the user has not supplied any parameters with invalid values.
     *
     * @hook validate build:project:create
     */
    public function validateCreateProject(CommandData $commandData)
    {
        // Ensure that all of our providers are given the credentials they requested.
        $this->providerManager()->validateCredentials();
    }

    /**
     * Copy CI files from the given/default repo.
     */
    public function copyCiFiles($ci_provider, $created_folder, $cms_version, $ci_template) {
        $fs = new Filesystem();
        $service_name = $ci_provider->getServiceName();

        $ciTemplateDir = $this->tempdir('ci-template-dir');
        $this->passthru("git -C $ciTemplateDir clone $ci_template --depth 1 .");

        $fs->mirror("$ciTemplateDir/$cms_version/.ci", "$created_folder/.ci");
        $fs->mirror("$ciTemplateDir/$cms_version/providers/$service_name/.", $created_folder);

        if (!is_dir("$created_folder/tests") && is_dir("$ciTemplateDir/$cms_version/tests")) {
            $fs->mirror("$ciTemplateDir/$cms_version/tests", "$created_folder/tests");
        }

        $composer_json = $this->getComposerJson($created_folder);
        if (!isset($composer_json['scripts']['build-assets'])) {
            $composer_json['scripts']['build-assets'] = "echo 'Nothing to do.'";
        }
        if (!isset($composer_json['scripts']['unit-test'])) {
            $composer_json['scripts']['unit-test'] = "echo 'No unit test step defined.'";


            if ($cms_version === 'd8' || $cms_version === 'd9') {
                // Linting and coding standards.
                $composer_json['scripts']['lint'] = "find web/modules/custom web/themes/custom -name '*.php' -exec php -l {} \\;";
                $composer_json['scripts']['code-sniff'] = [
                    "./vendor/bin/phpcs --standard=Drupal --extensions=php,module,inc,install,test,profile,theme,css,info,txt,md --ignore=node_modules,bower_components,vendor ./web/modules/custom",
                    "./vendor/bin/phpcs --standard=Drupal --extensions=php,module,inc,install,test,profile,theme,css,info,txt,md --ignore=node_modules,bower_components,vendor ./web/themes/custom",
                    "./vendor/bin/phpcs --standard=DrupalPractice --extensions=php,module,inc,install,test,profile,theme,css,info,txt,md --ignore=node_modules,bower_components,vendor ./web/modules/custom",
                    "./vendor/bin/phpcs --standard=DrupalPractice --extensions=php,module,inc,install,test,profile,theme,css,info,txt,md --ignore=node_modules,bower_components,vendor ./web/themes/custom",
                ];

                // Config export.
                $composer_json['extra']['build-env']['export-configuration'] = "drush config-export --yes";
            }
            elseif ($cms_version === 'wp') {
                // Linting and coding standards.
                $composer_json['scripts']['lint'] = [
                    "mkdir -p ./web/wp-content",
                    "find web/wp-content -name '*.php' -exec php -l {} \\;",
                ];
                $composer_json['scripts']['code-sniff'] = [
                    "mkdir -p ./web/wp-content",
                    "./vendor/bin/phpcs --standard=WordPress --extensions=php --ignore=node_modules,bower_components,vendor ./web/wp-content",
                ];
            }
            file_put_contents("$created_folder/composer.json", json_encode($composer_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        $fs->mkdir("$created_folder/web/modules/custom");
        $fs->touch("$created_folder/web/modules/custom/.gitkeep");

        $fs->mkdir("$created_folder/web/themes/custom");
        $fs->touch("$created_folder/web/themes/custom/.gitkeep");
    }

    /**
     * Create a new project from the requested source GitHub project.
     * Does the following operations:
     *  - Creates a git repository forked from the source project.
     *  - Creates a Pantheon site to run the tests on.
     *  - Sets up CI to test the repository.
     * In order to use this command, it is also necessary to provide
     * a set of secrets that are used to create the necessary projects,
     * and that are subsequentially cached in Circle CI for use during
     * the test run. Currently, these secrets should be provided via
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
     * CircleCI configuration:
     *   export CIRCLE_TOKEN=circle_personal_api_token
     *
     * GitLab/GitLabCI configuration:
     *   export GITLAB_TOKEN=gitlab_personal_access_token
     *
     * Composer authentication details (if necessary):
     *   export TERMINUS_BUILD_TOOLS_COMPOSER_AUTH=json_encoded_string
     *
     * Secrets that are not exported will be prompted.
     *
     * @authorize
     *
     * @command build:project:create
     * @aliases build-env:create-project
     * @param string $source Packagist org/name of source template project to fork or path to an existing project on the local filesystem. Paths must either start with ./ or be an absolute path.
     * @param string $target Simple name of project to create.
     * @option org Organization for the new project (defaults to authenticated user)
     * @option team Pantheon team
     * @option label The friendly name to use for the Pantheon site (defaults to the name of the Git site).
     * @option pantheon-site Name of Pantheon site to create (defaults to 'target' argument)
     * @option email email address to place in ssh-key
     * @option test-site-name The name to use when installing the test site.
     * @option admin-email The email address to use for the CMS admin.
     * @option admin-username The username to use for the CMS admin.
     * @option admin-password The password to use for the CMS admin when installing the test site.
     * @option stability Minimum allowed stability for template project.
     * @option git Specify a git provider. Options are github (default), gitlab, and bitbucket.
     * @option ci Specify a CI provider. Options are circleci, gitlab-pipelines, bitbucket-pipelines and githubactions. If not provided, CI will be assigned based on git provider choice (Note that the default CI for Github is CircleCI).
     * @option visibility The desired visibility of the provider repository. Options are public, internal, and private.
     * @option use-ssh Use SSH instead of HTTPS to create the provider repository.
     * @option region Specify a data residency region. See https://pantheon.io/docs/regions#available-regions for the current region options.
     * @option preserve-local-repository If given, use the repository in the existing source directory. Otherwise, use composer create-project to create a new local copy of the source project.
     * @option keep If given, clone a local copy of the project.
     * @option env Add extra environment variables to the CI environment. For example, --env='key=value' --env='another=v2'.
     * @option template-repository Composer repository if package is hosted on a private registry or url to git.
     * @option ci-template Git repo that contains the CI scripts that will be copied if there is no ci in the source project.
     * @option clu-cron-pattern Specify a cron pattern to override the given CI provider's clu task schedule, if applicable. For example, '0 0 * * 1' to run once a week at midnight on monday.
     * @option profile The profile to be used at the time of the installation.
     */
    public function createProject(
        $source,
        $target = '',
        $options = [
            'org' => '',
            'team' => null,
            'pantheon-site' => '',
            'label' => '',
            'email' => '',
            'test-site-name' => '',
            'admin-password' => '',
            'admin-email' => '',
            'admin-username' => '',
            'stability' => '',
            'env' => [],
            'preserve-local-repository' => false,
            'keep' => false,
            'use-ssh' => false,
            'ci' => '',
            'git' => 'github',
            'visibility' => 'public',
            'region' => '',
            'template-repository' => '',
            'ci-template' => 'git@github.com:pantheon-systems/tbt-ci-templates.git',
            'clu-cron-pattern' => '',
            'profile' => ''
        ])
    {
        $this->warnAboutOldPhp();

        // Copy options into ordinary variables
        $target_org = $options['org'];
        $site_name = $options['pantheon-site'];
        $team = $options['team'];
        $label = $options['label'];
        $stability = $options['stability'];
        $visibility = $options['visibility'];
        $region = $options['region'];
        $use_ssh = $options['use-ssh'];
        $ci_template = $options['ci-template'];
        $profile = $options['profile'];

        // Provide default values for other optional variables.
        if (empty($label)) {
          $label = $site_name;
        }

        // This target label is only used for the log messages below.
        $target_label = $target;
        if (!empty($target_org)) {
            $target_label = "$target_org/$target";
        }

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

        // Set the COMPOSER_AUTH environment variable if there's one defined in config.
        // We might need it to check out any private dependencies.
        $composerAuth = Config_Utility::getComposerAuthJson($this->site_provider->session());
        $backupAuth   = null;
        if ($composerAuth) {
            $backupAuth = getenv('COMPOSER_AUTH');
            putenv('COMPOSER_AUTH=' . $composerAuth);
        }

        // If using SSH, verify authentication works as expected.
        if ($use_ssh){
          if (!$this->git_provider->verifySSHConnect()) {
            throw new TerminusException('Unable to connect to {git} via SSH. Try `ssh -T {baseGitUrl}` to test the connection.', ['git' => $options['git'], 'baseGitUrl' => $this->git_provider->getBaseGitUrl()]);
          }
          $this->log()->notice('Verified SSH connection to Git provider');
        }

        // Pull down the source project
        $this->log()->notice('Create a local working copy of {src}', ['src' => $source]);
        $siteDir = $this->createFromSource($source, $target, $stability, $options);

        // Restore COMPOSER_AUTH if necessary.
        if (!is_null($backupAuth)) {
            putenv('COMPOSER_AUTH=' . $backupAuth);
        }

        $builder = $this->collectionBuilder();

        $version_info = new VersionTool();
        $info = $version_info->info($siteDir);
        $app = $info->application();

        if (!file_exists($siteDir . '/.ci')) {
            $cms_version = 'd';
            if ($app !== 'Drupal') {
                $cms_version = 'wp';
            }
            else {
                $version = $info->version();
                $cms_version .= substr($version, 0, 1);
            }
            $this->copyCiFiles($this->ci_provider, $siteDir, $cms_version, $ci_template);

            // If folder does not exists, assume we need to install composer deps.
            // Require basic testing general packages.
            exec("composer --working-dir=$siteDir require --no-update --dev dealerdirect/phpcodesniffer-composer-installer squizlabs/php_codesniffer");
            exec("composer --working-dir=$siteDir config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true");

            // Install packages depending on the application.
            if ($app === 'Drupal') {
                exec("composer --working-dir=$siteDir require --no-update pantheon-systems/quicksilver-pushback");
            } elseif (strtolower($app) === 'wordpress') {
                exec("composer --working-dir=$siteDir require --no-update --dev wp-coding-standards/wpcs");
            }
            exec("composer --working-dir=$siteDir update");
        }
        $prePushTime = 0;

        // $builder->setStateValue('ci-env', $ci_env)

        $builder

            // Create a repository
            ->progressMessage('Create Git repository {target}', ['target' => $target_label])
            /*
            ->taskRepositoryCreate()
                ->provider($this->git_provider)
                ->target($target)
                ->owningOrganization($target_org)
                ->dir($siteDir)
            */
            ->addCode(
                function ($state) use ($ci_env, $target, $target_org, $siteDir, $visibility) {

                    $target_project = $this->git_provider->createRepository($siteDir, $target, $target_org, $visibility);

                    $repositoryAttributes = $ci_env->getState('repository');
                    // $github_token = $repositoryAttributes->token();

                    // $target_project = $this->createGitHub($target, $siteDir, $target_org, $github_token);
                    $this->log()->notice('The target is {target}', ['target' => $target_project]);
                    $repositoryAttributes->setProjectId($target_project);
                })

            // TODO: rollback GitHub repository create

            // Create a Pantheon site
            ->progressMessage('Create Pantheon site {site}', ['site' => $site_name])
            ->addCode(
                function ($state) use ($site_name, $label, $team, $target, $siteDir, $region) {
                    // Look up our upstream.
                    $upstream = $this->autodetectUpstream($siteDir);

                    $this->log()->notice('About to create Pantheon site {site} in {team} with upstream {upstream}', ['site' => $site_name, 'team' => $team, 'upstream' => $upstream]);

                    try {
                        $site = $this->siteCreate($site_name, $label, $upstream, ['org' => $team, 'region' => $region]);
                    } catch (\Exception $e) {
                        // Catch exception only to print error and then throw it again.
                        $this->log()->error($e->getMessage());
                        throw $e;
                    }

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

                    // If a README.template.md file exists in the template repository append its contents to the new README.md.
                    if ( file_exists( "$siteDir/README.template.md" ) ) {
                        $readme = "\n\n" . file_get_contents( "$siteDir/README.template.md" );
                        $readme = str_replace( '%SITE_NAME%', $site_name, $readme );
                        file_put_contents( "$siteDir/README.md", $readme, FILE_APPEND );
                        unlink( "$siteDir/README.template.md" );
                    }

                    // If this site cannot create multidev environments, then configure
                    // it to always run tests on the dev environment.
                    $state['has-multidev-capability'] = $this->siteHasMultidevCapability($site);
                })

            // TODO: rollback Pantheon site create

            // Create new repository and make the initial commit
            ->progressMessage('Make initial commit')
            ->addCode(
                function ($state) use ($siteDir, $source) {
                    if (file_exists("$siteDir/web/modules/custom/.gitkeep")) {
                        $this->passthru("git -C $siteDir add -f web/modules/custom/.gitkeep");
                    }
                    if (file_exists("$siteDir/web/themes/custom/.gitkeep")) {
                        $this->passthru("git -C $siteDir add -f web/themes/custom/.gitkeep");
                    }
                    // This file may be in the repo but ignored (e.g. https://github.com/pantheon-upstreams/wordpress-project).
                    if (file_exists("$siteDir/pantheon.upstream.yml")) {
                        $this->passthru("git -C $siteDir add -f pantheon.upstream.yml");
                    }
                    $headCommit = $this->initialCommit($siteDir, $source);
                })

            ->progressMessage('Fix .gitignore file if needed')
            ->addCode(
                function () use ($siteDir) {
                    if (file_exists("$siteDir/.gitignore")) {
                        $gitignore_contents = file_get_contents("$siteDir/.gitignore");
                        if (preg_match('/#\s?:+\s?cut\s?:+/', $gitignore_contents) === 0) {
                            $lines_to_add = [
                                '# :::::::::::::::::::::: cut ::::::::::::::::::::::',
                                '',
                                '# Put ignore patterns for build artifacts above the cut line',
                                '# so they are ignored in the source repo and committed',
                                '# to Pantheon.',
                                '',
                            ];
                            $gitignore_contents = implode("\r\n", $lines_to_add) . $gitignore_contents;
                            file_put_contents("$siteDir/.gitignore", $gitignore_contents);
                            passthru("git -C {$siteDir} add .gitignore");
                            passthru("git -C {$siteDir} commit -m 'Update .gitignore to include cut line.'");

                        } else {
                            $this->log()->notice('.gitignore already contains the cut line. Nothing to do.');
                        }
                    }
                }
            )

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
                function ($state) use ($site_name, $siteDir, &$prePushTime) {
                    $prePushTime = time();
                    $this->pushCodeToPantheon("{$site_name}.dev", 'dev', $siteDir);
                    // Remove the commit added by pushCodeToPantheon; we don't need the build assets locally any longer.
                    $this->resetToCommit($siteDir, $state['initial_commit']);
                })

            // Install our site and export configuration.
            // Note that this also commits the configuration to the repository.
            ->progressMessage('Install CMS on Pantheon site {site}', ['site' => $site_name])
            ->addCode(
                function ($state) use ($ci_env, $site_name, $siteDir, &$prePushTime, $app, $profile) {
                    if (!$prePushTime) {
                        $prePushTime = time() - 1800;
                    }
                    list($site, $env) = $this->getSiteEnv("{$site_name}.dev");
                    // Wait for workflow to finish.
                    $this->waitForWorkflow($prePushTime, $site, $env, 'Change database version for an environment', null, 3);
                    $siteAttributes = $ci_env->getState('site');
                    $composer_json = $this->getComposerJson($siteDir);

                    // Install the site.
                    $site_install_options = [
                        'account-mail' => $siteAttributes->adminEmail(),
                        'account-name' => $siteAttributes->adminUsername(),
                        'account-pass' => $siteAttributes->adminPassword(),
                        'site-mail' => $siteAttributes->adminEmail(),
                        'site-name' => $siteAttributes->testSiteName(),
                        'site-url' => "https://dev-{$site_name}.pantheonsite.io",
                        'profile' => $profile
                    ];
                    $this->doInstallSite("{$site_name}.dev", $composer_json, $site_install_options, $app);

                    // Before any tests have been configured, export the
                    // configuration set up by the installer.
                    $this->exportInitialConfiguration("{$site_name}.dev", $siteDir, $composer_json, $site_install_options);
                })

            // Push the local working repository to the server
            ->progressMessage('Push initial code to {target}', ['target' => $target_label])
            /*
            ->taskRepositoryPush()
                ->provider($this->git_provider)
                ->target($this->target_project)
                ->dir($siteDir)
            */
            ->addCode(
                function ($state) use ($ci_env, $siteDir, $use_ssh) {
                    $repositoryAttributes = $ci_env->getState('repository');
                    $this->git_provider->pushRepository($siteDir, $repositoryAttributes->projectId(), $use_ssh);
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
        $this->passthru("git -C $repositoryDir commit -m 'Create new Pantheon site from $source'");
        return $this->getHeadCommit($repositoryDir);
    }

}
