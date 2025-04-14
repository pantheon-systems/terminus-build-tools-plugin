<?php

namespace Pantheon\TerminusBuildTools\API\Bitbucket;

use Pantheon\TerminusBuildTools\API\WebAPIInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialProviderInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialRequest;
use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\RepositoryEnvironment;
use Pantheon\TerminusBuildTools\ServiceProviders\ServiceTokenStorage;

/**
 * BitbucketAPITrait provides access to the BitbucketAPI, and manages
 * the credentials via a ServiceTokenStorage object
 */
trait BitbucketAPITrait
{
    protected $bitBucketUser;
    protected $bitBucketPassword;

    /** @var WebAPIInterface */
    protected $api;

    /**
     * @return ServiceTokenStorage
     */
    abstract public function getEnvironment();

    public function api()
    {
        if (!$this->api) {
            $this->api = $this->createApi($this->getEnvironment());
        }
        return $this->api;
    }

    protected function createApi($environment)
    {
        $api = new BitbucketAPI($environment);
        $api->setLogger($this->logger);

        return $api;
    }

    public function tokenKey()
    {
        return BitbucketAPI::BITBUCKET_PASS;
    }

    public function hasToken($key = false)
    {
        $repositoryEnvironment = $this->getEnvironment();
        return $repositoryEnvironment->hasToken($key);
    }

    public function token($key = false)
    {
        $repositoryEnvironment = $this->getEnvironment();
        return $repositoryEnvironment->token($key);
    }

    public function setToken($token)
    {
        $repositoryEnvironment = $this->getEnvironment();
        $repositoryEnvironment->setToken(BitbucketAPI::BITBUCKET_AUTH, $token);
    }

    public function getBitBucketUser()
    {
        return $this->bitBucketUser;
    }

    public function getBitBucketPassword()
    {
        return $this->bitBucketPassword;
    }

    public function setBitBucketUser($u)
    {
        $this->bitBucketUser = $u;
        $repositoryEnvironment = $this->getEnvironment();
        $repositoryEnvironment->setToken(BitbucketAPI::BITBUCKET_USER, $u);
    }

    public function setBitBucketPassword($pw)
    {
        $this->bitBucketPassword = $pw;
        $repositoryEnvironment = $this->getEnvironment();
        $repositoryEnvironment->setToken(BitbucketAPI::BITBUCKET_PASS, $pw);
    }

    /**
     * @inheritdoc
     */
    public function credentialRequests()
    {
        // Tell the credential manager that we require two credentials.
        // Note that the user request is a dependent request of the password
        // request. We validate the password against the BitBucktet API; if
        // we cannot use it to log in, then we re-prompt for both.
        $bitbucketUserRequest = (new CredentialRequest(BitbucketAPI::BITBUCKET_USER))
            ->setInstructions('')
            ->setPrompt("Enter your Bitbucket username")
            ->setRequired(true);

        $bitbucketPassRequest = (new CredentialRequest(BitbucketAPI::BITBUCKET_PASS))
            ->setInstructions('')
            ->setPrompt("Enter your Bitbucket app password")
            ->setRequired(true)
            ->setValidationCallbackErrorMessage("Your provided username and app password could not be used to authenticate with the BitBucket service. Please re-enter your credentials.")
            ->setValidateFn(
                function ($password, $otherCredentials) {
                    $username = $otherCredentials[BitbucketAPI::BITBUCKET_USER];

                    $tmpEnvironment = new RepositoryEnvironment();
                    $tmpEnvironment->setToken(BitbucketAPI::BITBUCKET_USER, $username);
                    $tmpEnvironment->setToken(BitbucketAPI::BITBUCKET_PASS, $password);
                    $api = $this->createApi($tmpEnvironment);
                    $userinfo = $api->request('user');

                    return true;
                }
            )
            ->addDependentRequest($bitbucketUserRequest);

        return [ $bitbucketUserRequest, $bitbucketPassRequest ];
    }

    /**
     * @inheritdoc
     */
    public function setCredentials(CredentialProviderInterface $credentials_provider)
    {
        // Since the `credentialRequests()` method declared that we need a
        // BITBUCKET_USER and BITBUCKET_PASS credentials, it will be available
        // for us to copy from the credentials provider when this method is called.
        $this->setBitBucketUser($credentials_provider->fetch(BitbucketAPI::BITBUCKET_USER));
        $this->setBitBucketPassword($credentials_provider->fetch(BitbucketAPI::BITBUCKET_PASS));
        $this->setToken(
            urlencode($this->getBitBucketUser())
            .':'.
            urlencode($this->getBitBucketPassword())
        );
    }

    /**
     * @inheritdoc
     */
    public function authenticatedUser()
    {
        return $this->getBitBucketUser();
    }
}
