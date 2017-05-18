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
 * Env Create Command
 */
class EnvCreateCommand extends BuildToolsBase
{

    /**
     * Create the specified multidev environment on the given Pantheon
     * site from the build assets at the current working directory.
     *
     * @command build:env:create
     * @alias build-env:create
     * @param string $site_env_id The site and env of the SOURCE
     * @param string $multidev The name of the env to CREATE
     * @option label What to name the environment in commit comments
     * @option clone-content Run terminus env:clone-content if the environment is re-used
     * @option db-only Only clone the database when runing env:clone-content
     * @option notify Command to exec to notify when a build environment is created
     */
    public function createBuildEnv(
        $site_env_id,
        $multidev,
        $options = [
            'label' => '',
            'clone-content' => false,
            'db-only' => false,
            'notify' => '',
        ])
    {
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $env_id = $env->getName();
        $env_label = $multidev;
        if (!empty($options['label'])) {
            $env_label = $options['label'];
        }

        // Fetch the site id also
        $siteInfo = $site->serialize();
        $site_id = $siteInfo['id'];

        // Check to see if '$multidev' already exists on Pantheon.
        $environmentExists = $site->getEnvironments()->has($multidev);

        // Check to see if we should create before pushing or after
        $createBeforePush = $this->commitChangesFile('HEAD', 'pantheon.yml');

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
        }

        $metadata = $this->pushCodeToPantheon($site_env_id, $multidev, '', $env_label);

        // Create a new environment for this test.
        if (!$environmentExists && !$createBeforePush) {
            // If the environment is created after the branch is pushed,
            // then there is never a race condition -- the new env is
            // created with the correct files from the specified branch.
            $this->create($site_env_id, $multidev);
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
            $this->cloneContent($target, $env_id, $options['db-only']);
        }

        // Set the target environment to sftp mode
        $this->connectionSet($target, 'sftp');

        // If '--notify' was passed, then exec the notify command
        if (!empty($options['notify'])) {
            $site_name = $site->getName();
            $project = $this->projectFromRemoteUrl($metadata['url']);
            $metadata += [
                'project' => $project,
                'site-id' => $site_id,
                'site' => $site_name,
                'env' => $multidev,
                'label' => $env_label,
                'dashboard-url' => "https://dashboard.pantheon.io/sites/{$site_id}#{$multidev}",
                'site-url' => "https://{$multidev}-{$site_name}.pantheonsite.io/",
            ];

            $command = $this->interpolate($options['notify'], $metadata);

            // Run notification command. Ignore errors.
            passthru($command);
        }
    }
}
