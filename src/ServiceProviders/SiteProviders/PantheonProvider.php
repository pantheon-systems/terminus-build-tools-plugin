<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\SiteProviders;

use Pantheon\TerminusBuildTools\Credentials\CredentialClientInterface;
use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CIState;
use Pantheon\TerminusBuildTools\Task\Ssh\PublicKeyReciever;
use Pantheon\TerminusBuildTools\Credentials\CredentialProviderInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialRequest;
use Pantheon\TerminusBuildTools\Utility\Config as Config_Utility;
use Pantheon\Terminus\Exceptions\TerminusException;

/**
 * The only kind of site provider we support is Pantheon.
 */
class PantheonProvider implements SiteProvider, CredentialClientInterface, PublicKeyReciever
{
    protected $siteEnvironment;
    protected $machineToken;
    protected $session;

    const PASSWORD_ERROR_MESSAGE = "The CMS admin password cannot contain the characters ! ; ` or $ due to a Pantheon platform limitation. Please select a new password.";

    const EMAIL_FORMAT_ERROR = "The email address '{email}' is not valid. Please set a valid email address via 'git config --global user.email <address>', or override this setting with the --{option} option.";

    const MACHINE_TOKEN_ERROR = "Please generate a Pantheon machine token, as described in https://pantheon.io/docs/machine-tokens/. Then log in via: \n\nterminus auth:login --machine-token=my_machine_token_value";

    const MISSING_SITE_NAME_ERROR = "A site name must be provided.";

    const PASSWORD_INSTRUCTIONS = "Enter the password you would like to use to log in to the CMS of your test site";

    const PASSWORD_PROMPT = "Enter test site CMS password: ";

    public function setMachineToken($token)
    {
        $this->machineToken = $token;
        return $this;
    }

    public function session()
    {
        return $this->session;
    }

    public function setSession($session)
    {
        $this->session = $session;
        return $this;
    }

    public function getServiceName()
    {
        return 'pantheon';
    }

    public function getEnvironment()
    {
        if (!$this->siteEnvironment) {
            // We should always be authenticated by the time we get here, but
            // we will test just to be sure.
            if (empty($this->machineToken)) {
                throw new TerminusException(self::MACHINE_TOKEN_ERROR);
            }
            $this->siteEnvironment = (new SiteEnvironment())
                ->setServiceName($this->getServiceName())
                ->setSiteToken($this->machineToken);
        }
        return $this->siteEnvironment;
    }

    /**
     * Not sure this is helpful; you cannot infer from a custom domain.
     */
    public function infer($url)
    {
        return
            strpos($url, 'pantheon.io') !== false ||
            strpos($url, 'pantheonsite.io') !== false ||
            strpos($url, 'drush.in') !== false;
    }

    /**
     * @inheritdoc
     */
    public function credentialRequests()
    {
        $siteNameRequest = (new CredentialRequest('SITE_NAME'))
            ->setOptionKey('pantheon-site')
            ->setRequired(false);

        $testSiteNameRequest = (new CredentialRequest('TEST_SITE_NAME'))
            ->setRequired(false);

        $gitEmailRequest = (new CredentialRequest('GIT_USER_EMAIL'))
            ->setOptionKey('email')
            ->setRequired(false);

        $adminEmailRequest = (new CredentialRequest('ADMIN_EMAIL'))
            ->setRequired(false);

        $adminUsernameRequest = (new CredentialRequest('ADMIN_USERNAME'))
            ->setRequired(false);

        $adminPasswordRequest = (new CredentialRequest('ADMIN_PASSWORD'))
            ->setInstructions(self::PASSWORD_INSTRUCTIONS)
            ->setPrompt(self::PASSWORD_PROMPT)
            ->setValidateFn([$this, 'validAdminPassword'])
            ->setValidationCallbackErrorMessage(self::PASSWORD_ERROR_MESSAGE)
            ->setRequired(true);

        $siteProfile = (new CredentialRequest('SITE_PROFILE'))
            ->setOptionKey('profile')
            ->setRequired(false);

        return [
            $siteNameRequest,
            $testSiteNameRequest,
            $gitEmailRequest,
            $adminEmailRequest,
            $adminPasswordRequest,
            $siteProfile
        ];
    }

    /**
     * @inheritdoc
     */
    public function setCredentials(CredentialProviderInterface $credentials_provider)
    {
        // The elements that `credentialRequests()` declared above will
        // be available when this method is called via the `fetch()` method.
        // The credential requests object will ensure these are taken
        // from environment variables or commandline options or user input
        // as appropriate.
        $site_name = $credentials_provider->fetch('SITE_NAME');
        $test_site_name = $credentials_provider->fetch('TEST_SITE_NAME');
        $git_email = $credentials_provider->fetch('GIT_USER_EMAIL');
        $admin_email = $credentials_provider->fetch('ADMIN_EMAIL');
        $admin_username = $credentials_provider->fetch('ADMIN_USERNAME');
        $adminPassword = $credentials_provider->fetch('ADMIN_PASSWORD');

        // We should always have a site name by the time we get here,
        // but check again and fail if we do not, just to be sure.
        if (empty($site_name)) {
            throw new TerminusException(self::MISSING_SITE_NAME_ERROR);
        }

        // If no test site name was provided, use the site name
        if (empty($test_site_name)) {
            $test_site_name = $site_name;
        }

        // If no git user email was provided, look up the current git user email
        if (empty($git_email)) {
            $git_email = exec('git config --global user.email');
        }

        // If no admin email was provided, use the git user email
        if (empty($admin_email)) {
            $admin_email = $git_email;
        }

        // If no admin username was provided, use 'admin'
        if (empty($admin_username)) {
            $admin_username = 'admin';
        }

        // If no admin password was provided, generate a random one
        if (empty($adminPassword)) {
            $adminPassword = mt_rand();
        }

        // Make sure admin password is valid
        if (!$this->validAdminPassword($adminPassword)) {
            throw new TerminusException(self::PASSWORD_ERROR_MESSAGE);
        }

        // Validate the format of the email addresses
        $this->validateEmail('email', $git_email);
        $this->validateEmail('admin-email', $admin_email);

        // Pass the credentials on to the site environment
        $env = $this->getEnvironment()
            ->setSiteName($site_name)
            ->setTestSiteName($test_site_name)
            ->setAdminPassword($adminPassword)
            ->setAdminEmail($admin_email)
            ->setAdminUsername($admin_username)
            ->setGitEmail($git_email);

        // Assign COMPOSER_AUTH if defined in config.yml or environment.
        if ($composerAuth = Config_Utility::getComposerAuthJson($this->session())) {
          $env->setComposerAuth($composerAuth);
        }
    }

    /**
     * Check to see if the provided email address is valid.
     */
    protected function validateEmail($emailOptionName, $emailValue)
    {
        // http://www.regular-expressions.info/email.html
        if (preg_match('/^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,63}$/i', $emailValue)) {
            return;
        }

        throw new TerminusException(self::EMAIL_FORMAT_ERROR, ['email' => $emailValue, 'option' => $emailOptionName]);
    }

    /**
     * Return whether or not the provided admin password is usable on Pantheon.
     * We send the password via PantheonSSH (e.g. via Drush or WP-CLI commands),
     * so characters that cannot be used there are rejected.
     */
    public function validAdminPassword($adminPassword)
    {
       return strpbrk($adminPassword, '!;$`') === false;
    }

    /**
     * Because we are a PublicKeyReciever, we will be provided with
     * a copy of the public key.
     */
    public function addPublicKey(CIState $ci_env, $publicKey)
    {
        // Add the public key to Pantheon.
        $this->session()->getUser()->getSSHKeys()->addKey($publicKey);
    }

    /**
     * Retrieves this providers tokens.
     */
    public function getSecretValues() {
        return [
          'token' => $this->machineToken
        ];
    }
}
