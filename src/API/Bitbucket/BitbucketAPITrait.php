<?php

namespace Pantheon\TerminusBuildTools\API\Bitbucket;

use Pantheon\TerminusBuildTools\API\WebAPI;
use Pantheon\TerminusBuildTools\API\WebAPIInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialProviderInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialRequest;
use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;
use Pantheon\TerminusBuildTools\ServiceProviders\ServiceTokenStorage;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

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
            $this->api = new BitbucketAPI($this->getEnvironment());
            $this->api->setLogger($this->logger);
        }
        return $this->api;
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
        // Tell the credential manager that we require two credentials
        $bitbucketUserRequest = (new CredentialRequest(BitbucketAPI::BITBUCKET_USER))
            ->setInstructions('')
            ->setPrompt("Enter your Bitbucket username: ")
            ->setRequired(true);

        $bitbucketPassRequest = (new CredentialRequest(BitbucketAPI::BITBUCKET_PASS))
            ->setInstructions('')
            ->setPrompt("Enter your Bitbucket account password or an app password: ")
            ->setRequired(true);

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
