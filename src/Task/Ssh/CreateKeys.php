<?php
namespace Pantheon\TerminusBuildTools\Task\Ssh;

use Robo\Result;
use Robo\Task\BaseTask;

/**
 * Create a public / private key pair for testing, and add them to
 * any provider that declares, by way of implementing PrivateKeyReciever
 * and/or PublicKeyReciever, that it needs to be configured with such.
 */
class CreateKeys extends BaseTask
{
    protected $providers = [];
    protected $ci_env;

    public function environment($ci_env)
    {
        $this->ci_env = $ci_env;
    }

    public function provider($provider)
    {
        $this->providers[] = $provider;
    }

    /**
     * Return all of the providers that implement the given interface.
     *
     * @param $interfaceName The desired interface
     * @return ProviderEnvironment[]
     */
    public function all($interfaceName)
    {
        return array_filter(
            $this->providers,
            function ($item) use ($interfaceName) {
                return $item instanceof $interfaceName;
            }
        );
    }

    protected function create()
    {
        $siteAttributes = $this->ci_env->getState('site');
        $repositoryAttributes = $this->ci_env->getState('repository');
        $site_name = $siteAttributes->siteName();
        $git_email = $siteAttributes->gitEmail();
        $target_project = $repositoryAttributes->projectId();
        $target_label = strtr($target_project, '/', '-');

        // Create an ssh key pair dedicated to use in these tests.
        // Change the email address to "user+ci-SITE@domain.com" so
        // that these keys can be differentiated in the Pantheon dashboard.
        $ssh_key_email = str_replace('@', "+ci-{$target_label}@", $git_email);
        $this->printTaskInfo('Create ssh key pair for {email}', ['email' => $ssh_key_email]);
        return $this->createSshKeyPair($ssh_key_email, $site_name . '-key');
    }

    /**
     * Create a unique ssh key pair to use in testing
     *
     * @param string $ssh_key_email
     * @param string $prefix
     * @return [string, string]
     */
    protected function createSshKeyPair($ssh_key_email, $prefix = 'id')
    {
        // TODO: tmp dir strategy
        // $tmpkeydir = $this->tempdir('ssh-keys');
        $tmpkeydir = '/tmp/ssh-keys';
        if (!is_dir($tmpkeydir)) {
            mkdir($tmpkeydir);
        }
        $privateKey = "$tmpkeydir/$prefix";
        $publicKey = "$privateKey.pub";

        // TODO: make a util class method to call passthru and throw on error
        passthru("ssh-keygen -m PEM -t rsa -b 4096 -f $privateKey -N '' -C '$ssh_key_email'");

        return [$publicKey, $privateKey];
    }

    public function provide($list, $functionName, $v1, $v2 = null)
    {
        foreach($list as $item) {
            $fn = [$item, $functionName];
            $fn($this->ci_env, $v1, $v2);
        }
    }

    public function run()
    {
        list($publicKey, $privateKey) = $this->create();
        $keyPairReceivers = $this->all(KeyPairReciever::class);
        $this->provide($keyPairReceivers, 'addKeyPair', $publicKey, $privateKey);

        $privateKeyReceivers = $this->all(PrivateKeyReciever::class);
        $this->provide($privateKeyReceivers, 'addPrivateKey', $privateKey);

        $publicKeyReceivers = $this->all(PublicKeyReciever::class);
        $this->provide($publicKeyReceivers, 'addPublicKey', $publicKey);

        return Result::success($this);
    }
}
