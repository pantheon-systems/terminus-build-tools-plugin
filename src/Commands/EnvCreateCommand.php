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
 * Env Create Command
 */
class EnvCreateCommand extends BuildToolsBase
{

    /**
     * Create the specified multidev environment on the given Pantheon
     * site from the build assets at the current working directory.
     *
     * @command build:env:create
     * @aliases build-env:create
     * @param string $site_env_id The site and env of the SOURCE
     * @param string $multidev The name of the env to CREATE
     * @option label What to name the environment in commit comments
     * @option clone-content Run terminus env:clone-content if the environment is re-used
     * @option db-only Only clone the database when runing env:clone-content
     * @option notify Do not use this deprecated option. Previously used for a build notify command, currently ignored.
     * @option message Commit message to include when committing assets to Pantheon
     * @option pr-id Post notification comment to a specific PR instead of the commit hash.
     * @option no-git-force set this flag to omit the --force flag from 'git add' and 'git push'
     * @option create-before-push Force the environment to be created first before pushing code.
     * @option git-ref-compare The Git commit identifier to use when checking for changes to pantheon configuraiton YAML files.
     */
    public function createBuildEnv(
        $site_env_id,
        $multidev,
        $options = [
            'label' => '',
            'clone-content' => false,
            'notify' => '',
            'db-only' => false,
            'message' => '',
            'pr-id' =>  '',
            'no-git-force' =>  false,
            'create-before-push' => false,
            'git-ref-compare' => 'HEAD',
        ])
    {
        list($site, $env) = $this->getSiteEnv($site_env_id);

        $env_label = $multidev;
        if (!empty($options['label'])) {
            $env_label = $options['label'];
        }

        $pr_id = $options['pr-id'];

        // Revert to build:env:push if build:env:create is run against dev.
        if ('dev' === $multidev) {
            $this->log()->notice('dev has been passed to the multidev option. Reverting to dev:env:push as dev is not a multidev environment.');
            // Run build:env:push.
            $this->pushCodeToPantheon($site_env_id, $multidev, '', $env_label, $options['message'], $options['git-force']);
            return;
        }

        if ($env->id === $multidev) {
            $this->log()->notice('Cannot create an environment from itself. Aborting.');
            return;
        }

        $env_id = $env->getName();

        $doNotify = false;

        // Fetch the site id also
        $siteInfo = $site->serialize();
        $site_id = $siteInfo['id'];

        // Check to see if '$multidev' already exists on Pantheon.
        $environmentExists = $site->getEnvironments()->has($multidev);

        // Check to see if we should create before pushing or after
        $createBeforePush = $options['create-before-push'] || $this->commitChangesFile($options['git-ref-compare'], 'pantheon.yml') || $this->commitChangesFile($options['git-commit-compare'], 'pantheon.upstream.yml');

        if (!$environmentExists && $createBeforePush) {
            // If pantheon.yml exists, then we need to create the environment
            // in advance, before we push our change. It is more
            // efficient to push the branch first, and then create
            // the multidev, as in this instance, we do not need
            // to call waitForCodeSync(). However, changes to pantheon.yml
            // will not be applied unless we push our change.
            // To allow pantheon.yml to be processed, we will
            // create the multidev environment, and then push the code.
            $this->create($site_env_id, $multidev);
            $doNotify = true;
        }

        $metadata = $this->pushCodeToPantheon($site_env_id, $multidev, '', $env_label, $options['message'], $options['no-git-force']);

        // Create a new environment for this test.
        if (!$environmentExists && !$createBeforePush) {
            // If the environment is created after the branch is pushed,
            // then there is never a race condition -- the new env is
            // created with the correct files from the specified branch.
            $this->create($site_env_id, $multidev);
            $doNotify = true;
        }

        // Clear the environments, so that they will be re-fetched.
        // Otherwise, the new environment will not be found immediately
        // after it is first created. If we set the connection mode to
        // git mode, then Terminus will think it is still in sftp mode
        // if we do not re-fetch.
        // TODO: Require Terminus ^1.1 in our composer.json and simplify old code below.
        if (method_exists($site, 'unsetEnvironments')) {
            $site->unsetEnvironments();
        }
        else {
            // In Terminus 1.0.0, Site::unsetEnvironments() did not exist,
            // and $site->environments was public. If the line below is crashing
            // for you, perhaps you are using a dev version of Terminus from
            // 20 Feb - 7 Mar 2017. Use something newer or older instead.
            $site->environments = null;
        }

        // Get (or re-fetch) a reference to our target multidev site.
        $target = $site->getEnvironments()->get($multidev);

        // If we did not create our environment, then run clone-content
        // instead -- but only if requested. No point in running 'clone'
        // if the user plans on re-installing Drupal.
        if ($environmentExists && $options['clone-content']) {
            $this->cloneContent($target, $env, $options['db-only']);
        }

        // Set the target environment to sftp mode
        $this->connectionSet($target, 'sftp');

        // TODO: Push to repo provider

        // Run notification command
        if ($doNotify == true) {
            $site_name = $site->getName();
            $project = $this->projectFromRemoteUrl($metadata['url']);
            $dashboard_url = "https://dashboard.pantheon.io/sites/{$site_id}#{$multidev}";

            $metadata += [
                'project' => $project,
                'site-id' => $site_id,
                'site' => $site_name,
                'env' => $multidev,
                'pr_id' => $pr_id,
                'label' => $env_label,
                'dashboard-url' => $dashboard_url,
                'site-url' => "https://{$multidev}-{$site_name}.pantheonsite.io/",
                'message' => "Created multidev environment [{$multidev}]({$dashboard_url}) for {$site_name}."
            ];

            if ( ! empty( $pr_id ) ) {
                $command = $this->interpolate('terminus build:comment:add:pr --pr_id [[pr_id]] --message [[message]] --site_url [[site-url]]', $metadata);
            } else {
                $command = $this->interpolate('terminus build:comment:add:commit --sha [[sha]] --message [[message]] --site_url [[site-url]]', $metadata);
            }

            // Run notification command. Ignore errors.
            passthru($command);
        }
    }
}
