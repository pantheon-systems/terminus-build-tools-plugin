<?php
/**
 * Terminus Plugin that contain a collection of commands useful during
 * the build step on a [Pantheon](https://www.pantheon.io) site that uses
 * a Git PR workflow.
 *
 * See README.md for usage information.
 */

namespace Pantheon\TerminusBuildTools\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\TerminusBuildTools\Utility\UrlParsing;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Robo\Common\ProcessUtils;
use Composer\Semver\Comparator;
use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CIState;
use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;
use Pantheon\Terminus\DataStore\FileStore;
use Pantheon\TerminusBuildTools\Credentials\CredentialManager;
use Pantheon\TerminusBuildTools\ServiceProviders\ProviderManager;
use Pantheon\Terminus\Helpers\LocalMachineHelper;
use Pantheon\Terminus\Commands\WorkflowProcessingTrait;
use Pantheon\Terminus\Models\Environment;

use Robo\Contract\BuilderAwareInterface;
use Robo\LoadAllTasks;

/**
 * Build Tool Base Command
 */
class BuildToolsBase extends TerminusCommand implements SiteAwareInterface, BuilderAwareInterface
{
    use LoadAllTasks; // uses TaskAccessor, which uses BuilderAwareTrait
    use SiteAwareTrait;
    use WorkflowProcessingTrait;

    const TRANSIENT_CI_DELETE_PATTERN = 'ci-';
    const PR_BRANCH_DELETE_PATTERN = 'pr-';
    const DEFAULT_DELETE_PATTERN = self::TRANSIENT_CI_DELETE_PATTERN;
    const DEFAULT_WORKFLOW_TIMEOUT = 180;
    const SECRETS_DIRECTORY = '.build-secrets';
    const SECRETS_REMOTE_DIRECTORY = 'private/' . self::SECRETS_DIRECTORY;

    protected $tmpDirs = [];

    protected $provider_manager;
    protected $ci_provider;
    protected $git_provider;
    protected $site_provider;

    /**
     * Constructor
     *
     * @param ProviderManager $provider_manager Provider manager may be injected for testing (not used). It is
     * not passed in by Terminus.
     */
    public function __construct($provider_manager = null)
    {
        $this->provider_manager = $provider_manager;
    }

    /**
     * Set GIT_SSH_COMMAND so we can disable strict host key checking. This allows builds to run without pauses
     * for user input.
     *
     * By not specifying a command in the hook below it will apply to any command from this class (or class that
     * extends this class, such as all of Build Tools).
     *
     * @hook init
     */
    public function noStrictHostKeyChecking()
    {
        // Set the GIT_SSH_COMMAND environment variable to avoid SSH Host Key prompt.
        // By using putenv, the environment variable won't persist past this PHP run.
        // Setting the Known Hosts File to /dev/null and the LogLevel to quiet prevents
        // this from persisting for a user regularly as well as the warning about adding
        // the SSH key to the known hosts file.
        putenv("GIT_SSH_COMMAND=ssh -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -o LogLevel=QUIET");
    }

    public function providerManager()
    {
        if (!$this->provider_manager) {
            // TODO: how can we do DI from within a Terminus Plugin? Huh?
            // Delayed initialization is one option.
            $credential_store = new FileStore($this->getConfig()->get('cache_dir') . '/build-tools');
            $credentialManager = new CredentialManager($credential_store);
            $credentialManager->setUserId($this->loggedInUserEmail());
            $this->provider_manager = new ProviderManager($credentialManager, $this->getConfig());
            $this->provider_manager->setLogger($this->logger);
        }
        return $this->provider_manager;
    }

    /**
     * Given a git provider and (optionally) a ci provider, return the
     *
     */
    public function selectCIProvider($git_provider_class_or_alias, $ci_provider_class_or_alias = '')
    {
        // If using GitLab, override the CI choice as GitLabCI is the only option.
        // CircleCI theoretically works with GitLab, but its API will not start
        // up testing on new projects seamlessly, so we can't really use it here.
        if ($git_provider_class_or_alias == 'gitlab') {
            $ci_provider_class_or_alias = 'gitlabci';
        }

        // If using bitbucket and ci is not explicitly provided,
        // assume bitbucket pipelines
        if (($git_provider_class_or_alias == 'bitbucket') && (!$ci_provider_class_or_alias)) {
            $ci_provider_class_or_alias = 'pipelines';
        }

        // If nothing was provided and no default was inferred, use Circle.
        if (!$ci_provider_class_or_alias) {
            $ci_provider_class_or_alias = 'circleci';
        }

        return $ci_provider_class_or_alias;
    }

    protected function createGitProvider($git_provider_class_or_alias)
    {
        $this->git_provider = $this->providerManager()->createProvider($git_provider_class_or_alias, \Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\GitProvider::class);
    }

    protected function createCIProvider($ci_provider_class_or_alias)
    {
        $this->ci_provider = $this->providerManager()->createProvider($ci_provider_class_or_alias, \Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CIProvider::class);
    }

    protected function createSiteProvider($site_provider_class_or_alias)
    {
        $this->site_provider = $this->providerManager()->createProvider($site_provider_class_or_alias, \Pantheon\TerminusBuildTools\ServiceProviders\SiteProviders\SiteProvider::class);
        $this->site_provider->setMachineToken($this->recoverSessionMachineToken());
        $this->site_provider->setSession($this->session());
    }

    protected function createProviders($git_provider_class_or_alias, $ci_provider_class_or_alias, $site_provider_class_or_alias = 'pantheon')
    {
        if (!empty($ci_provider_class_or_alias)) {
            $this->createCIProvider($ci_provider_class_or_alias);
        }
        if (!empty($git_provider_class_or_alias)) {
            $this->createGitProvider($git_provider_class_or_alias);
        }
        if (!empty($site_provider_class_or_alias)) {
            $this->createSiteProvider($site_provider_class_or_alias);
        }
    }

    protected function getUrlFromBuildMetadata($site_name_and_env)
    {
        $buildMetadata = $this->retrieveBuildMetadata($site_name_and_env);
        return $this->getMetadataUrl($buildMetadata);
    }

    protected function getMetadataUrl($buildMetadata)
    {
        $site_name_and_env = $buildMetadata['site'];
        // Get the build metadata from the Pantheon site. Fail if there is
        // no build metadata on the master branch of the Pantheon site.
        $buildMetadata = $buildMetadata + ['url' => ''];
        if (empty($buildMetadata['url'])) {
            throw new TerminusException('The site {site} was not created with the build-env:create-project command; it therefore cannot be used with this command.', ['site' => $site_name_and_env]);
        }
        return $buildMetadata['url'];
    }

    protected function inferGitProviderFromUrl($url)
    {
        $provider = $this->providerManager()->inferProvider($url, \Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\GitProvider::class);
        if (!$provider) {
             throw new TerminusException('Could not figure out which git repository service to use with {url}.', ['url' => $url]);
        }
        $this->git_provider = $provider;
        return $provider;
    }

    /**
     * Terminus requires php 5.5, so we know we have at least that version
     * if we get this far.  Warn the user if they are using php 5.5, though,
     * as we recommend php 5.6 or later (e.g. for Drupal 8.3.0.)
     */
    protected function warnAboutOldPhp()
    {
        if (Comparator::lessThan(PHP_VERSION, '5.6.0')) {
            $this->log()->warning('You are using php {version}; it is strongly recommended that you use at least php 5.6. Note that older versions of php will not work with newer template projects (e.g. Drupal 8.3.0).', ['version' => PHP_VERSION]);
        }
    }

    /**
     * Get the email address of the user that is logged-in to Pantheon
     */
    protected function loggedInUserEmail()
    {
        if (!$this->session()->isActive()) {
            $this->log()->notice('No active session.');
            return;
        }

        $user_data = $this->session()->getUser()->fetch()->serialize();
        if (!array_key_exists('email', $user_data)) {
            $this->log()->notice('No email address in active session data.');
            return;
        }

        // Look up the email address of the active user (as auth:whoami does).
        return $user_data['email'];
    }

    /**
     * Recover the Pantheon session's machine token.
     */
    protected function recoverSessionMachineToken()
    {
        $email_address = $this->loggedInUserEmail();
        if (!$email_address) {
            return;
        }

        // Try to look up the machine token using the Terminus API.
        $tokens = $this->session()->getTokens();
        $token = $tokens->get($email_address);
        $machine_token = $token->get('token');

        // If we can't get the machine token through regular Terminus API,
        // then serialize all of the tokens and see if we can find it there.
        // This is a workaround for a Terminus bug.
        if (empty($machine_token)) {
            $raw_data = $tokens->serialize();
            if (isset($raw_data[$email_address]['token'])) {
                $machine_token = $raw_data[$email_address]['token'];
            }
        }

        return $machine_token;
    }

    /**
     * Determine whether or not this site can create multidev environments.
     */
    protected function siteHasMultidevCapability($site)
    {
        // Can our site create multidevs?
        $settings = $site->get('settings');
        if (!$settings) {
            return false;
        }
        return $settings->max_num_cdes > 0;
    }

    /**
     * Return the list of available Pantheon organizations
     */
    protected function availableOrgs()
    {
        $orgs = array_map(
            function ($org) {
                return $org->getLabel();
            },
            $this->session()->getUser()->getOrganizations()
        );
        return $orgs;
    }

    /**
     * Fetch the environment variable 'CIRCLE_TOKEN', or throw an exception if it is not set.
     * @return string
     */
    protected function getRequiredCircleToken()
    {
        $circle_token = getenv('CIRCLE_TOKEN');
        if (empty($circle_token)) {
            throw new TerminusException("Please generate a Circle CI personal API token token, as described in https://circleci.com/docs/api/#authentication. Then run: \n\nexport CIRCLE_TOKEN=my_personal_api_token_value");
        }
        return $circle_token;
    }

    /**
     * Return the set of environment variables to save on the CI server.
     *
     * @param string $site_name
     * @param array $options
     * @return CIState
     */
    public function getCIEnvironment($extra_env)
    {
        $ci_env = new CIState();

        // Add in extra environment provided on command line via
        // --env='key=value' --env='another=v2'
        $envState = new ProviderEnvironment();
        foreach ($extra_env as $env) {
            list($key, $value) = explode('=', $env, 2) + ['',''];
            if (!empty($key) && !empty($value)) {
                $envState[$key] = $value;
            }
        }
        $ci_env->storeState('env', $envState);

        return $ci_env;
    }

    /**
     * Provide some simple aliases to reduce typing when selecting common repositories
     */
    protected function expandSourceAliases($source)
    {
        //
        // key:   org/project of template repository
        // value: list of aliases
        //
        // If the org is missing from the template repository, then
        // pantheon-systems is assumed.
        //
        $aliases = [
            'git@github.com:pantheon-upstreams/drupal-composer-managed.git' => ['d9', 'drops-9'],
            'example-drops-8-composer' => ['d8', 'drops-8'],
            'example-drops-7-composer' => ['d7', 'drops-7'],
            'example-wordpress-composer' => ['wp', 'wordpress'],
        ];

        // Convert the defaults into a more straightforward mapping:
        //   shortcut: project
        $map = [strtolower($source) => $source];
        foreach ($aliases as $full => $shortcuts) {
            foreach ($shortcuts as $alias) {
                $map[$alias] = $full;
            }
        }

        // Add in the user shortcuts.
        $user_shortcuts = $this->getConfig()->get('command.build.project.create.shortcuts', []);
        $map = array_merge($map, $user_shortcuts);

        return $map[strtolower($source)];
    }

    /**
     * Detect the upstream to use based on the contents of the source repository.
     * Upstream is irrelevant, save for the fact that this is the only way to
     * set the framework on Pantheon at the moment.
     */
    protected function autodetectUpstream($siteDir)
    {
        $upstream = $this->autodetectUpstreamAtDir("$siteDir/web");
        if ($upstream) {
            return $upstream;
        }
        $upstream = $this->autodetectUpstreamAtDir($siteDir);
        if ($upstream) {
            return $upstream;
        }
        // Can't tell? Assume Drupal 8.
        return 'Empty Upstream';
    }

    protected function autodetectUpstreamAtDir($siteDir)
    {
        $upstream_map = [
          'core/misc/drupal.js' => 'empty', // Drupal 8
          'misc/drupal.js' => 'empty-7', // Drupal 7
          'wp-config.php' => 'empty-wordpress', // WordPress
          'wp-config-sample.php' => 'empty-wordpress', // Also WordPress
          'wp-config-pantheon.php' => 'empty-wordpress', // Also also WordPress
        ];

        foreach ($upstream_map as $file => $upstream) {
            if (file_exists("$siteDir/$file")) {
                return $upstream;
            }
        }

        return false;
    }

    /**
     * Create our project from source, either via composer create-project,
     * or from an existing source directory. Record the build metadata.
     */
    protected function createFromSource($source, $target, $stability = '', $options = [])
    {
        if (is_dir($source)) {
            return $this->useExistingSourceDirectory($source, $options['preserve-local-repository']);
        }
        else {
            return $this->createFromSourceProject($source, $target, $stability, $options['template-repository']);
        }
    }

    protected function useExistingSourceDirectory($source, $preserve_local_repository)
    {
        if ($preserve_local_repository) {
            if (!is_dir("$source/.git")) {
                throw new TerminusException('Specified --preserve-local-repository, but the directory {source} does not contains a .git directory.', compact('$source'));
            }
        }
        else {
            if (is_dir("$source/.git")) {
                throw new TerminusException('The directory {source} already contains a .git directory. Use --preserve-local-repository if you wish to use this existing repository.', compact('$source'));
            }
        }
        return $source;
    }

    /**
     * Use composer create-project to create a new local copy of the source project.
     */
    protected function createFromSourceProject($source, $target, $stability = '', $template_repository = '')
    {
        $source_project = $source;
        $additional_commands = [];
        $create_project_options = [];

        $this->log()->notice('Creating project and resolving dependencies.');

        // If the source is 'org/project:dev-branch', then automatically
        // set the stability to 'dev'.
        if (empty($stability) && preg_match('#:dev-#', $source)) {
            $stability = 'dev';
        }
        // Pass in --stability to `composer create-project` if user requested it.
        $stability_flag = empty($stability) ? '' : "--stability $stability";

        // Create a working directory
        $tmpsitedir = $this->tempdir('local-site');

        $stability = $stability ?? 'stable';

        if ($source === 'git@github.com:pantheon-upstreams/drupal-composer-managed.git' && empty($stability_flag)) {
            // This is not published in packagist so it needs dev stability.
            $stability_flag = '--stability dev';
            $additional_commands[] = "mkdir $tmpsitedir/$target/vendor";
            $additional_commands[] = "composer --working-dir=$tmpsitedir/$target require pantheon-upstreams/upstream-configuration:'*' --no-update";
            $additional_commands[] = "composer --working-dir=$tmpsitedir/$target config minimum-stability dev";
            $additional_commands[] = "composer --working-dir=$tmpsitedir/$target install -n";
            // Restore stability or set to default value.
            $additional_commands[] = "composer --working-dir=$tmpsitedir/$target config minimum-stability $stability";
            $create_project_options[] = '--no-install';
        }
        $create_project_options[] = $stability_flag;

        $repository = '';

        if ($template_repository) {
            if (substr($template_repository, -4) === '.git') {
                // It's a git repository.
                $repository = ' --repository="{\"url\": \"' . $template_repository . '\", \"type\": \"vcs\"}"';
            }
            else {
                $repository = ' --repository=' . $template_repository;
            }
        }
        else {
            $items = $this->getSourceAndTemplateFromSource($source);
            $source_project = $items['source'];
            if (!empty($items['template-repository'])) {
                $repository = ' --repository="' . $items['template-repository'] . '"';
                $additional_commands[] = "composer --working-dir=$tmpsitedir/$target config minimum-stability dev";
                $additional_commands[] = "composer --working-dir=$tmpsitedir/$target install -n";
                // Restore stability or set to default value.
                $additional_commands[] = "composer --working-dir=$tmpsitedir/$target config minimum-stability $stability";
                $create_project_options[] = '--no-install';
                $create_project_options[] = '--stability dev';
            }
        }

        $create_project_command = sprintf('composer create-project --working-dir=%s %s %s %s -n %s',
            $tmpsitedir,
            $repository,
            $source_project,
            $target,
            implode(' ', $create_project_options)
        );
        $this->passthru($create_project_command);
        foreach ($additional_commands as $command) {
            $this->passthru($command);
        }
        $local_site_path = "$tmpsitedir/$target";
        return $local_site_path;
    }

    /**
     * Given a source, such as:
     *    pantheon-systems/example-drops-8-composer:dev-lightning-fist-2
     * Return the 'project' portion, including the org, e.g.:
     *    pantheon-systems/example-drops-8-composer
     */
    protected function sourceProjectFromSource($source)
    {
        return preg_replace('/:.*/', '', $source);
    }

    /**
     * Given a source:
     *   If it's a composer repository such as:
     *     pantheon-systems/example-drops-8-composer:dev-1.x
     *   Return the full source in $items array: pantheon-systems/example-drops-8-composer:dev-1.x
     *
     *   If it's a git repo such as:
     *     git@github.com:pantheon-systems/example-drops-8.git
     *   Return the template-repository in json format as expected by composer create-project
     *     and the source from the package name in the composer.json file.
     */
    protected function getSourceAndTemplateFromSource($source) {
        $items = [
            'source' => '',
            'template-repository' => '',
        ];
        if (preg_match('/^[A-Za-z0-9\-]*\/[A-Za-z0-9\-]*:?[A-Za-z0-9\-]*$/', $source)) {
            $items['source'] = $source;
        }
        else {
            $items['template-repository'] = '{\"url\": \"' . $source . '\", \"type\": \"vcs\"}';
            $templateDir = $this->tempdir('template-dir');
            $this->passthru("git -C $templateDir clone $source --depth 1 .");
            $composer_json_contents = file_get_contents($templateDir . '/composer.json');
            if ($contents = json_decode($composer_json_contents)) {
                if (!empty($contents->name)) {
                    $items['source'] = $contents->name;
                }
            }
        }
        return $items;
    }

    /**
     * Return the sha of the HEAD commit.
     */
    protected function getHeadCommit($repositoryDir)
    {
        return exec("git -C $repositoryDir rev-parse HEAD");
    }

    /**
     * Reset to the specified commit (or remove the last commit)
     */
    protected function resetToCommit($repositoryDir, $resetToCommit = 'HEAD^')
    {
        $this->passthru("git -C $repositoryDir reset --hard $resetToCommit");
    }

    // TODO: if we could look up the commandfile for
    // Pantheon\Terminus\Commands\Site\CreateCommand,
    // then we could just call its 'create' method
    public function siteCreate($site_name, $label, $upstream_id, $options = ['org' => null, 'region' => null,])
    {
        if ($this->sites()->nameIsTaken($site_name)) {
            throw new TerminusException('The site name {site_name} is already taken.', compact('site_name'));
        }

        $workflow_options = [
            'label' => $label,
            'site_name' => $site_name
        ];
        $user = $this->session()->getUser();

        // Locate upstream
        $upstream = $user->getUpstreams()->get($upstream_id);

        // Locate organization
        if (!empty($org_id = $options['org'])) {
            $org = $user->getOrgMemberships()->get($org_id)->getOrganization();
            $workflow_options['organization_id'] = $org->id;
        }

        // Add the site region.
        if (!empty($region = $options['region'])) {
            $workflow_options['preferred_zone'] = $region;
        }

        // Create the site
        $this->log()->notice('Creating a new Pantheon site {name}', ['name' => $site_name]);
        $workflow = $this->sites()->create($workflow_options);
        while (!$workflow->checkProgress()) {
            // @TODO: Add Symfony progress bar to indicate that something is happening.
        }

        // Deploy the upstream
        if ($site = $this->getSite($workflow->get('waiting_for_task')->site_id)) {
            $this->log()->notice('Deploying {upstream} to Pantheon site', ['upstream' => $upstream_id]);
            $workflow = $site->deployProduct($upstream->id);
            while (!$workflow->checkProgress()) {
                // @TODO: Add Symfony progress bar to indicate that something is happening.
            }
            $this->log()->notice('Deployed CMS');
        }

        return $this->getSite($site_name);
    }

    /**
     * Run an env:clone-content operation
     * @param Environment $target
     * @param Environment $from_env
     * @param bool $db_only
     * @param bool $files_only
     */
    public function cloneContent(Environment $target, Environment $from_env, $db_only = false, $files_only = false)
    {
        if ($from_env->id === $target->id) {
            $this->log()->notice("Skipping clone since environments are the same.");
            return;
        }

        $from_name = $from_env->getName();

        // Clone files if we're only doing files, or if "only do db" is not set.
        if ($files_only || !$db_only) {
            $workflow = $target->cloneFiles($from_env);
            $this->log()->notice(
                "Cloning files from {from_name} environment to {target_env} environment",
                ['from_name' => $from_name, 'target_env' => $target->getName()]
            );
            while (!$workflow->checkProgress()) {
                // @TODO: Add Symfony progress bar to indicate that something is happening.
            }
            $this->log()->notice($workflow->getMessage());
        }

        // Clone database if we're only doing the database, or if "only do files" is not set.
        if ($db_only || !$files_only) {
            $workflow = $target->cloneDatabase($from_env);
            $this->log()->notice(
                "Cloning database from {from_name} environment to {target_env} environment",
                ['from_name' => $from_name, 'target_env' => $target->getName()]
            );
            while (!$workflow->checkProgress()) {
                // @TODO: Add Symfony progress bar to indicate that something is happening.
            }
            $this->log()->notice($workflow->getMessage());
        }
    }

    protected function doInstallSite(
        $site_env_id,
        $composer_json = [],
        $site_install_options = [
            'account-mail' => '',
            'account-name' => '',
            'account-pass' => '',
            'site-mail' => '',
            'site-name' => '',
            'profile' => ''
        ],
        $app = 'Drupal'
        )
    {
        $command_template = $this->getInstallCommandTemplate($composer_json, $app);
        return $this->runCommandTemplateOnRemoteEnv($site_env_id, $command_template, "Install site", $site_install_options);
    }

    protected function runCommandTemplateOnRemoteEnv(
        $site_env_id,
        $command_templates,
        $operation_label,
        $options
    ) {
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $this->log()->notice('{op} on {site}', ['op' => $operation_label, 'site' => $site_env_id]);

        // Set the target environment to sftp mode prior to running the command
        $this->connectionSet($env, 'sftp');

        foreach ($options as $key => $val) {
          if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
            $metadata[$key] = $this->escapeArgument($val);
          }
        }
        foreach ((array)$command_templates as $command_template) {
            $command_line = $this->interpolate($command_template, $metadata);
            $redacted_metadata = $this->redactMetadata($metadata, ['account-pass']);
            $redacted_command_line = $this->interpolate($command_template, $redacted_metadata);

            $this->log()->notice(' - {cmd}', ['cmd' => $redacted_command_line]);
            $result = $this->sendCommandViaSsh(
                $env,
                $command_line,
                function ($type, $buffer) {
                }
            );
            $output = $result['output'];
            if ($result['exit_code']) {
                throw new TerminusException('{op} failed with exit code {status}', ['op' => $operation_label, 'status' => $result['exit_code']]);
            }
        }
    }

    /**
     * Sends a command to an environment via SSH.
     * We would rather not duplicate this method from Terminus.
     *
     * @param string $command The command to be run on the platform
     */
    protected function sendCommandViaSsh($env, $command)
    {
        $ssh_command = $this->getConnectionString($env) . ' ' . ProcessUtils::escapeArgument($command);
        return $this->getContainer()->get(LocalMachineHelper::class)->execute(
            $ssh_command,
            function ($type, $buffer) {
                },
            false
        );
    }

    /**
     * We would rather not duplicate this method from Terminus.
     *
     * @return string SSH connection string
     */
    private function getConnectionString($env)
    {
        $sftp = $env->sftpConnectionInfo();
        return vsprintf(
            'ssh -T %s@%s -p %s -o "StrictHostKeyChecking=no" -o "AddressFamily inet"',
            [$sftp['username'], $sftp['host'], $sftp['port'],]
        );
    }

    protected function exportInitialConfiguration($site_env_id, $repositoryDir, $composer_json, $options)
    {
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $command_template = $this->getExportConfigurationTemplate($composer_json);
        if (empty($command_template)) {
            return;
        }

        // Run the 'export configuration' command
        $this->runCommandTemplateOnRemoteEnv($site_env_id, $command_template, "Export configuration", $options);

        // Commit the changes. Quicksilver is not set up to push these back
        // to the Git provider from the dev branch, but we don't want to leave these changes
        // uncommitted.
        $env->commitChanges('Install site and export configuration.');

        // TODO: How do we know where the configuration will be exported to?
        // Perhaps we need to export to a temporary directory where we control
        // the path. Perhaps export to ':tmp/config' instead of ':code/config'.
        $this->rsync($site_env_id, ':code/config', $repositoryDir);

        $this->passthru("git -C $repositoryDir add config");
        exec("git -C $repositoryDir status --porcelain", $outputLines, $status);
        if (!empty($outputLines)) {
            $this->passthru("git -C $repositoryDir commit -m 'Export configuration'");
        }
    }

    /**
     * Remove sensitive information from a metadata array.
     */
    protected function redactMetadata($metadata, $keys_to_redact)
    {
        foreach ($keys_to_redact as $key) {
            $metadata[$key] = '"[REDACTED]"';
        }
        return $metadata;
    }

    /**
     * Determine the command to use to install the site.
     */
    protected function getInstallCommandTemplate($composer_json, $app)
    {
        if (isset($composer_json['extra']['build-env']['install-cms'])) {
            return $composer_json['extra']['build-env']['install-cms'];
        }
        if (strtolower($app) === 'wordpress') {
            return [
                'wp core install --title={site-name} --url={site-url} --admin_user={account-name} --admin_email={account-mail} --admin_password={account-pass}',
                'wp option update permalink_structure "/%postname%/"',
            ];
        }
        return 'drush site-install {profile} --yes --account-mail={account-mail} --account-name={account-name} --account-pass={account-pass} --site-mail={site-mail} --site-name={site-name}';
    }

    /**
     * Determine the command to use to export configuration.
     */
    protected function getExportConfigurationTemplate($composer_json)
    {
        if (isset($composer_json['extra']['build-env']['export-configuration'])) {
            return $composer_json['extra']['build-env']['export-configuration'];
        }

        return '';
    }

    /**
     * Read the composer.json file from the provided site directory.
     */
    protected function getComposerJson($siteDir)
    {
        $composer_json_file = "$siteDir/composer.json";
        if (!file_exists($composer_json_file)) {
            return [];
        }
        return json_decode(file_get_contents($composer_json_file), true);
    }

    /**
     * Escape one command-line arg
     *
     * @param string $arg The argument to escape
     * @return RowsOfFields
     */
    protected function escapeArgument($arg)
    {
        // Omit escaping for simple args.
        if (preg_match('/^[a-zA-Z0-9_-]*$/', $arg)) {
            return $arg;
        }
        return ProcessUtils::escapeArgument($arg);
    }

    /**
     * Push code to Pantheon -- common routine used by 'create-project', 'create' and 'push-code' commands.
     */
    public function pushCodeToPantheon(
        $site_env_id,
        $multidev = '',
        $repositoryDir = '',
        $label = '',
        $message = '',
        $noGitForce = FALSE)
    {
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $dev_env = $site->getEnvironments()->get('dev');
        $env_id = $env->getName();
        $multidev = empty($multidev) ? $env_id : $multidev;
        $branch = ($multidev == 'dev') ? 'master' : $multidev;
        $env_label = $multidev;
        if (!empty($label)) {
            $env_label = $label;
        }

        if (empty($message)) {
            $message = "Build assets for $env_label.";
        }

        if (empty($repositoryDir)) {
            $repositoryDir = getcwd();
        }

        // Sanity check: only push from directories that have .git and composer.json
        // Note: we might want to use push-to-pantheon even if there isn't a composer.json,
        // e.g. when using build:env:create with drops-7.
        foreach (['.git'] as $item) {
            if (!file_exists("$repositoryDir/$item")) {
                throw new TerminusException('Cannot push from {dir}: missing {item}.', ['dir' => $repositoryDir, 'item' => $item]);
            }
        }

        $this->log()->notice('Pushing code to {multidev} using branch {branch}.', ['multidev' => $multidev, 'branch' => $branch]);

        // Fetch the site id also
        $siteInfo = $site->serialize();
        $site_id = $siteInfo['id'];

        // Check to see if '$multidev' already exists on Pantheon.
        $environmentExists = $site->getEnvironments()->has($multidev);

        // Add a remote named 'pantheon' to point at the Pantheon site's git repository.
        // Skip this step if the remote is already there (e.g. due to CI service caching).
        $this->addPantheonRemote($dev_env, $repositoryDir);
        // $this->passthru("git -C $repositoryDir fetch pantheon");

        // Record the metadata for this build
        $metadata = $this->getBuildMetadata($repositoryDir);
        $this->recordBuildMetadata($metadata, $repositoryDir);

        // Drupal 7: Drush requires a settings.php file. Add one to the
        // build results if one does not already exist.
        $default_dir = "$repositoryDir/" . (is_dir("$repositoryDir/web") ? 'web/sites/default' : 'sites/default');
        $settings_file = "$default_dir/settings.php";
        if (is_dir($default_dir) && !is_file($settings_file)) {
          file_put_contents($settings_file, "<?php\n");
          $this->log()->notice('Created empty settings.php file {settingsphp}.', ['settingsphp' => $settings_file]);
        }

        // Remove any .git directories added by composer from the set of files
        // being committed. Ideally, there will be none. We cannot allow any to
        // remain, though, as git will interpret these as submodules, which
        // will prevent the contents of directories containing .git directories
        // from being added to the main repository.
        $finder = new Finder();
        $fs = new Filesystem();
        $fs->remove(
          $finder
            ->directories()
            ->in("$repositoryDir")
            ->ignoreDotFiles(false)
            ->ignoreVCS(false)
            ->depth('> 0')
            ->name('.git')
            ->getIterator()
        );

        // Create a new branch and commit the results from anything that may
        // have changed. We presume that the source repository is clean of
        // any unwanted files prior to the build step (e.g. after a clean
        // checkout in a CI environment.)
        $this->passthru("git -C $repositoryDir checkout -B $branch");
        if ($this->respectGitignore($repositoryDir) || $noGitForce === TRUE) {
            // In "Integrated Composer" mode, we will not commit ignored files
            $this->passthru("git -C $repositoryDir add .");
        }
        else {
            $this->passthru("git -C $repositoryDir add --force -A .");
        }

        // Now that everything is ready, commit the build artifacts.
        $this->passthru($this->interpolate("git -C {repositoryDir} commit -q -m [[message]]", ['repositoryDir' => $repositoryDir, 'message' => $message]));

        // If the environment does exist, then we need to be in git mode
        // to push the branch up to the existing multidev site.
        if ($environmentExists) {
            $target = $site->getEnvironments()->get($multidev);
            $this->connectionSet($target, 'git');
        }

        // Push the branch to Pantheon
        $preCommitTime = time();
        $forceFlag = $noGitForce ? "" : "--force";
        $this->passthru("git -C $repositoryDir push $forceFlag -q pantheon $branch");

        // If the environment already existed, then we risk encountering
        // a race condition, because the 'git push' above will fire off
        // an asynchronous update of the existing update. If we switch to
        // sftp mode before this sync is completed, then the converge that
        // sftp mode kicks off will corrupt the environment.
        if ($environmentExists) {
            $this->waitForCodeSync($preCommitTime, $site, $multidev);
        }

        return $metadata;
    }

    /**
     * respectGitignore determines if we should respoect the .gitignore
     * file (rather than use 'git add --force). This is experimental.
     */
    protected function respectGitignore($repositoryDir)
    {
        if ($this->checkIntegratedComposerSetting("$repositoryDir/pantheon.yml", false)) {
            return false;
        }
        return $this->checkIntegratedComposerSetting("$repositoryDir/pantheon.yml", true)
            || $this->checkIntegratedComposerSetting("$repositoryDir/pantheon.upstream.yml", true);
    }

    /**
     * checkIntegratedComposerSetting checks if the build step switch is on
     * in just one (pantheon.yml or pantheon.upstream.yml) config file.
     */
    private function checkIntegratedComposerSetting($pantheonYmlPath, $desiredValue)
    {
        if (!file_exists($pantheonYmlPath)) {
            return false;
        }
        $contents = file_get_contents($pantheonYmlPath);

        $expected = $desiredValue ? 'true' : 'false';

        // build_step_demo: true
        //  - or -
        // build_step: true
        return preg_match("#^build_step(_demo)?: $expected\$#m", $contents);
    }

    /**
     * projectFromRemoteUrl converts from a url e.g. https://github.com/org/repo
     * to the "org/repo" portion of the provided url.
     */
    protected function projectFromRemoteUrl($url)
    {
        $org_user = UrlParsing::orgUserFromRemoteUrl($url);
        $repository = UrlParsing::repositoryFromRemoteUrl($url);
        return "$org_user/$repository";
    }

    protected function getMatchRegex($item, $multidev_delete_pattern)
    {
        $match = $item;
        // If the name is less than the maximum length, then require
        // an exact match; otherwise, do a 'starts with' test.
        if (strlen($item) < 11) {
            $match .= '$';
        }
        // Strip the multidev delete pattern from the beginning of
        // the match. The multidev env name was composed by prepending
        // the delete pattern to the branch name, so this recovers
        // the branch name.
        $match = preg_replace("%$multidev_delete_pattern%", '', $match);
        // Constrain match to only match from the beginning
        $match = "^$match";

        return $match;
    }

    protected function deleteEnv($env, $deleteBranch = false)
    {
        $workflow = $env->delete(
            ['delete_branch' => true,]
        );
        $this->processWorkflow($workflow);
        $this->log()->notice('Deleted the multidev environment {env}.', ['env' => $env->id,]);
    }

    /**
     * Return a list of multidev environments matching the provided
     * pattern, sorted with oldest first.
     *
     * @param string $site_id Site to check.
     * @param string $multidev_delete_pattern Regex of environments to select.
     */
    protected function oldestEnvironments($site_id, $multidev_delete_pattern)
    {
        // Get a list of all of the sites
        $env_list = $this->getSite($site_id)->getEnvironments()->serialize();

        // Filter out the environments that do not match the multidev delete pattern
        $env_list = array_filter(
            $env_list,
            function ($item) use ($multidev_delete_pattern) {
                return preg_match("%$multidev_delete_pattern%", $item['id']);
            }
        );

        // Sort the environments by creation date, with oldest first
        uasort(
            $env_list,
            function ($a, $b) {
                if ($a['created'] == $b['created']) {
                    return 0;
                }
                return ($a['created'] < $b['created']) ? -1 : 1;
            }
        );

        return $env_list;
    }

    /**
     * Create a new multidev environment
     *
     * @param string $site_env Source site and environment.
     * @param string $multidev Name of environment to create.
     */
    public function create($site_env, $multidev)
    {
        list($site, $env) = $this->getSiteEnv($site_env, 'dev');
        $this->log()->notice("Creating multidev {env} for site {site}", ['site' => $site->getName(), 'env' => $multidev]);
        $workflow = $site->getEnvironments()->create($multidev, $env);
        while (!$workflow->checkProgress()) {
            // TODO: Add workflow progress output
        }
        $this->log()->notice($workflow->getMessage());
    }

    /**
     * Set the connection mode to 'sftp' or 'git' mode, and wait for
     * it to complete.
     *
     * @param Pantheon\Terminus\Models\Environment $env
     * @param string $mode
     */
    public function connectionSet($env, $mode)
    {
        // Refresh environment data.
        $env->fetch();
        if ($mode === $env->get('connection_mode')) {
            return;
        }
        $workflow = $env->changeConnectionMode($mode);
        if (is_string($workflow)) {
            $this->log()->notice($workflow);
        } else {
            while (!$workflow->checkProgress()) {
                // TODO: Add workflow progress output
            }
            $this->log()->notice($workflow->getMessage());
        }
    }

    /**
     * Wait for a workflow to complete.
     *
     * @param int $startTime Ignore any workflows that started before the start time.
     * @param string $workflow The workflow message to wait for.
     */
    protected function waitForCodeSync($startTime, $site, $env_name)
    {
        $this->waitForWorkflow($startTime, $site, $env_name);
    }

    protected function waitForWorkflow($startTime, $site, $env_name, $expectedWorkflowDescription = '', $maxWaitInSeconds = null, $maxNotFoundAttempts = null)
    {
        if (empty($expectedWorkflowDescription)) {
            $expectedWorkflowDescription = "Sync code on $env_name";
        }

        if (null === $maxWaitInSeconds) {
            $maxWaitInSecondsEnv = getenv('TERMINUS_BUILD_TOOLS_WORKFLOW_TIMEOUT'); 
            $maxWaitInSeconds = $maxWaitInSecondsEnv ? $maxWaitInSecondsEnv : self::DEFAULT_WORKFLOW_TIMEOUT; 
        }

        $startWaiting = time();
        $firstWorkflowDescription = null;
        $notFoundAttempts = 0;
        $workflows = $site->getWorkflows();

        while(true) {
            $site = $this->getsite($site->id);
            // Refresh env on each interation.
            $index = 0;
            $workflows->reset();
            $workflow_items = $workflows->fetch(['paged' => false,])->all();
            $found = false;
            foreach ($workflow_items as $workflow) {
                $workflowCreationTime = $workflow->get('created_at');

                $workflowDescription = str_replace('"', '', $workflow->get('description'));
                if ($index === 0) {
                    $firstWorkflowDescription = $workflowDescription;
                }
                $index++;

                if ($workflowCreationTime < $startTime) {
                    // We already passed the start time.
                    break;
                }

                if (($expectedWorkflowDescription === $workflowDescription)) {
                    $workflow->fetch();
                    $this->log()->notice("Workflow '{current}' {status}.", ['current' => $workflowDescription, 'status' => $workflow->getStatus(), ]);
                    $found = true;
                    if ($workflow->isSuccessful()) {
                        $this->log()->notice("Workflow succeeded");
                        return;
                    }
                }
            }
            if (!$found) {
                $notFoundAttempts++;
                $this->log()->notice("Current workflow is '{current}'; waiting for '{expected}'", ['current' => $firstWorkflowDescription, 'expected' => $expectedWorkflowDescription]);
                if ($maxNotFoundAttempts && $notFoundAttempts === $maxNotFoundAttempts) {
                    $this->log()->warning("Attempted '{max}' times, giving up waiting for workflow to be found", ['max' => $maxNotFoundAttempts]);
                    break;
                }
            }
            // Wait a bit, then spin some more
            sleep(5);
            if (time() - $startWaiting >= $maxWaitInSeconds) {
                $this->log()->warning("Waited '{max}' seconds, giving up waiting for workflow to finish", ['max' => $maxWaitInSeconds]);
                break;
            }
        }
    }

    /**
     * Return the metadata for this build.
     *
     * @return string[]
     */
    public function getBuildMetadata($repositoryDir)
    {
        $buildMetadata = [
          'url'         => $this->sanitizeUrl(exec("git -C $repositoryDir config --get remote.origin.url")),
          'ref'         => exec("git -C $repositoryDir rev-parse --abbrev-ref HEAD"),
          'sha'         => $this->getHeadCommit($repositoryDir),
          'comment'     => exec("git -C $repositoryDir log --pretty=format:%s -1"),
          'commit-date' => exec("git -C $repositoryDir show -s --format=%ci HEAD"),
          'build-date'  => date("Y-m-d H:i:s O"),
        ];

        if (!isset($this->git_provider)) {
            $this->git_provider = $this->inferGitProviderFromUrl($buildMetadata['url']);
        }
        
        $this->git_provider->alterBuildMetadata($buildMetadata);

        return $buildMetadata;
    }

    /**
     * Sanitize a build url: if http[s] is used, strip any token that exists.
     *
     * @param string $url
     * @return string
     */
    protected function sanitizeUrl($url)
    {
        return preg_replace('#://[^@/]*@#', '://', $url);
    }

    /**
     * Write the build metadata into the build results prior to committing them.
     *
     * @param string[] $metadata
     */
    public function recordBuildMetadata($metadata, $repositoryDir)
    {
        $buildMetadataFile = "$repositoryDir/build-metadata.json";
        $metadataContents = json_encode($metadata, JSON_UNESCAPED_SLASHES);
        $this->log()->notice('Set {file} to {metadata}.', ['metadata' => $metadataContents, 'file' => basename($buildMetadataFile)]);

        file_put_contents($buildMetadataFile, $metadataContents);
    }

    /**
     * Iterate through the different environments, and keep fetching their
     * metadata until we find one that has a 'url' component.
     *
     * @param string $site_id The site to operate on
     * @param stirng[] $oldestEnvironments List of environments
     * @return string
     */
    protected function retrieveRemoteUrlFromBuildMetadata($site_id, $oldestEnvironments)
    {
        foreach ($oldestEnvironments as $env) {
            try {
                $metadata = $this->retrieveBuildMetadata("{$site_id}.{$env}");
                if (!empty($metadata['url'])) {
                    return $metadata['url'];
                }
            }
            catch(\Exception $e) {
            }
        }
        return '';
    }

    /**
     * Get the build metadata from a remote site.
     *
     * @param string $site_env_id
     * @return string[]
     */
    public function retrieveBuildMetadata($site_env_id)
    {
        $src = ':code/build-metadata.json';
        $dest = '/tmp/build-metadata.json';

        $status = $this->rsync($site_env_id, $src, $dest);
        if ($status == 0) {
            $metadataContents = file_get_contents($dest);
            $metadata = json_decode($metadataContents, true);
        }

        $metadata['site'] = $site_env_id;

        @unlink($dest);

        return $metadata;
    }

    /**
     * Add or refresh the 'pantheon' remote
     */
    public function addPantheonRemote($env, $repositoryDir)
    {
        // Refresh the remote is already there (e.g. due to CI service caching), just in
        // case. If something changes, this info is NOT removed by "rebuild without cache".
        if ($this->hasPantheonRemote($repositoryDir)) {
            passthru("git -C $repositoryDir remote remove pantheon");
        }
        $connectionInfo = $env->connectionInfo();
        $gitUrl = $connectionInfo['git_url'];
        $this->passthru("git -C $repositoryDir remote add pantheon $gitUrl");
    }

    /**
     * Check to see if there is a remote named 'pantheon'
     */
    protected function hasPantheonRemote($repositoryDir)
    {
        exec("git -C $repositoryDir remote show", $output);
        return array_search('pantheon', $output) !== false;
    }

    /**
     * Substitute replacements in a string. Replacements should be formatted
     * as {key} for raw value, or [[key]] for shell-escaped values.
     *
     * @param string $message
     * @param string[] $context
     * @return string[]
     */
    protected function interpolate($message, array $context)
    {
        // build a replacement array with braces around the context keys
        $replace = array();
        foreach ($context as $key => $val) {
            if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                $replace[sprintf('{%s}', $key)] = $val;
                $replace[sprintf('[[%s]]', $key)] = ProcessUtils::escapeArgument($val);
            }
        }

        // interpolate replacement values into the message and return
        return strtr($message, $replace);
    }

    /**
     * Call rsync to or from the specified site.
     *
     * @param string $site_env_id Remote site
     * @param string $src Source path to copy from. Start with ":" for remote.
     * @param string $dest Destination path to copy to. Start with ":" for remote.
     * @param boolean $ignoreIfNotExists Silently fail and do not return error if remote source does not exist.
     */
    protected function rsync($site_env_id, $src, $dest, $ignoreIfNotExists = true)
    {
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $env_id = $env->getName();

        $siteInfo = $site->serialize();
        $site_id = $siteInfo['id'];

        $siteAddress = "$env_id.$site_id@appserver.$env_id.$site_id.drush.in:";

        $src = preg_replace('/^:/', $siteAddress, $src);
        $dest = preg_replace('/^:/', $siteAddress, $dest);

        $this->log()->notice('Rsync {src} => {dest}', ['src' => $src, 'dest' => $dest]);
        $status = 0;
        $command = "rsync -rlIvz --ipv4 --exclude=.git -e 'ssh -p 2222 -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -o LogLevel=QUIET' $src $dest >/dev/null 2>&1";
        passthru($command, $status);
        if (!$ignoreIfNotExists && in_array($status, [0, 23]))
        {
            throw new TerminusException('Command `{command}` failed with exit code {status}', ['command' => $command, 'status' => $status]);
        }

        return $status;
    }

    /**
     * Return 'true' if the specified file was changed in the provided commit.
     */
    protected function commitChangesFile($commit, $file)
    {
        // If there are any errors, we will presume that the file in
        // question does not exist in the repository and treat that as
        // "file did not change" (in other words, ignore errors).
        exec("git show --name-only $commit -- $file", $outputLines, $result);

        return ($result == 0) && !empty($outputLines);
    }

    /**
     * Call passthru; throw an exception on failure.
     *
     * @param string $command
     */
    protected function passthru($command, $loggedCommand = '')
    {
        $result = 0;
        $loggedCommand = empty($loggedCommand) ? $command : $loggedCommand;
        // TODO: How noisy do we want to be?
        $this->log()->notice("Running {cmd}", ['cmd' => $loggedCommand]);
        passthru($command, $result);

        if ($result != 0) {
            throw new TerminusException('Command `{command}` failed with exit code {status}', ['command' => $loggedCommand, 'status' => $result]);
        }
    }

    function passthruRedacted($command, $secret)
    {
        $loggedCommand = str_replace($secret, 'REDACTED', $command);
        $command .= " | sed -e 's/$secret/REDACTED/g'";

        return $this->passthru($command, $loggedCommand);
    }

    /**
     * Call exec; throw an exception on failure.
     *
     * @param string $command
     * @return string[]
     */
    protected function exec($command)
    {
        $result = 0;
        $this->log()->notice("Running {cmd}", ['cmd' => $command]);
        exec($command, $outputLines, $result);

        if ($result != 0) {
            throw new TerminusException('Command `{command}` failed with exit code {status}', ['command' => $command, 'status' => $result]);
        }
        return $outputLines;
    }

    /**
     * Download a copy of the secrets.json file from the appropriate site.
     */
    protected function downloadSecrets($site_env_id, $filename)
    {
        $workdir = $this->tempdir();
        $this->rsync($site_env_id, ":files/" . self::SECRETS_REMOTE_DIRECTORY . "/$filename", $workdir, true);

        if (file_exists("$workdir/$filename"))
        {
            $secrets = file_get_contents("$workdir/$filename");
            $secretValues = json_decode($secrets, true);
            return $secretValues;
        }

        return [];
    }

    /**
     * Upload a modified secrets.json to the target Pantheon site.
     */
    protected function uploadSecrets($site_env_id, $secretValues, $filename)
    {
        $workdir = $this->tempdir();
        mkdir("$workdir/" . self::SECRETS_REMOTE_DIRECTORY, 0777, true);

        file_put_contents("$workdir/" . self::SECRETS_REMOTE_DIRECTORY . "/$filename", json_encode($secretValues));
        $this->rsync($site_env_id, "$workdir/private", ':files/');
    }

    protected function writeSecrets($site_env_id, $secretValues, $clear, $file)
    {
        $values = [];
        if (!$clear)
        {
            $values = $this->downloadSecrets($site_env_id, $file);
        }

        $values = array_replace($values, $secretValues);

        $this->uploadSecrets($site_env_id, $values, $file);
    }

    protected function deleteSecrets($site_env_id, $key, $file)
    {
        $secretValues = [];
        if (!empty($key))
        {
            $secretValues = $this->downloadSecrets($site_env_id, $file);
            unset($secretValues[$key]);
        }
        $this->uploadSecrets($site_env_id, $secretValues, $file);
    }

    // Create a temporary directory
    public function tempdir($prefix='php', $dir=FALSE)
    {
        $this->registerCleanupFunction();
        $tempfile=tempnam($dir ? $dir : sys_get_temp_dir(), $prefix ? $prefix : '');
        if (file_exists($tempfile)) {
            unlink($tempfile);
        }
        mkdir($tempfile);
        chmod($tempfile, 0700);
        if (is_dir($tempfile)) {
            $this->tmpDirs[] = $tempfile;
            return $tempfile;
        }
    }

    /**
     * Register our shutdown function if it hasn't already been registered.
     */
    public function registerCleanupFunction()
    {
        static $registered = false;
        if ($registered) {
            return;
        }

        // Insure that $workdir will be deleted on exit.
        register_shutdown_function([$this, 'cleanup']);
        $registered = true;
    }

    // Delete our work directory on exit.
    public function cleanup()
    {
        if (empty($this->tmpDirs)) {
            return;
        }

        $fs = new Filesystem();
        $fs->remove($this->tmpDirs);
    }
}
