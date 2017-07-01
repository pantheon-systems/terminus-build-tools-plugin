<?php

namespace Pantheon\TerminusBuildTools\Credentials;

use Symfony\Component\Console\Style\SymfonyStyle;
use Pantheon\Terminus\DataStore\DataStoreInterface;

/**
 * The credential manager stores and fetches credentials from a cache.
 * When necessary, it will prompt the user to provide a needed credential.
 */
class CredentialManager implements CredentialProviderInterface
{
    protected $credentialRequests = [];
    protected $transientCache = [];
    protected $userId;

    public function __construct(DataStoreInterface $storage)
    {
        $this->cache = $storage;
    }

    /**
     * Identify the user that owns the cache. We segregate cached
     * credentials, so that the credential manager will never use
     * credentials that were cached for a different user.
     *
     * If the user is not identified, then the cache is disabled,
     * and the user will need to enter their credentials every time,
     * or provide them via environment variables.
     */
    public function setUserId($userId)
    {
        $this->userId = $userId;
    }

    /**
     * Record a set of credential request objects.
     *
     * @param CredentialRequestInterface[] $requests
     */
    public function add(array $requests)
    {
        foreach ($requests as $request) {
            $this->addRequest($request);
        }
    }

    /**
     * Record a credential request object.
     */
    public function addRequest(CredentialRequestInterface $request)
    {
        $this->credentialRequests[] = $request;
        $this->getEnvironmentVariableIfAvailable($request);
    }

    /**
     * Determine whether or not a credential exists in the cache
     */
    public function has($id)
    {
        if ($this->hasTransient($id)) {
            return true;
        }
        $key = $this->credentialKey($id);
        return $this->cache->has($key);
    }

    /**
     * Fetch a credential from the cache
     */
    public function fetch($id)
    {
        if ($this->hasTransient($id)) {
            return $this->fetchTransient($id);
        }
        $key = $this->credentialKey($id);
        if (empty($key)) {
            return;
        }
        $credential = $this->cache->get($key);
        $credential = trim($credential);
        $this->storeTransient($id, $credential);

        return $credential;
    }

    /**
     * Fetch a credential from the cache
     */
    public function store($id, $credential)
    {
        $credential = trim($credential);
        $this->storeTransient($id, $credential);
        $key = $this->credentialKey($id);
        if (!empty($key)) {
            $this->cache->set($key, $credential);
        }
    }

    /**
     * Remove a credential from the cache
     */
    public function remove($id)
    {
        $key = $this->credentialKey($id);
        if (!empty($key)) {
            $this->cache->remove($key);
        }
    }

    /**
     * Ask the user to enter needed credentials.
     *
     * @param CredentialRequestInterface[] $credentialRequests
     */
    public function ask(SymfonyStyle $io)
    {
        foreach ($this->credentialRequests as $request) {
            if (!$this->has($request->id())) {
                $this->askOne($request, $io);
            }
        }
    }

    protected function askOne(CredentialRequestInterface $request, SymfonyStyle $io)
    {
        $instructions = $request->instructions();
        $prompt = $request->prompt();

        $io->write($instructions);

        while (true) {
            $io->write("\n\n");
            $credential = $io->askHidden($prompt);
            $credential = trim($credential);

            if ($request->validate($credential)) {
                $this->store($request->id(), $credential);
                return;
            }
            $io->write($request->validationErrorMessage());
        }
    }

    protected function getEnvironmentVariableIfAvailable(CredentialRequestInterface $request)
    {
        $envVar = $request->environmentVariable();
        if (empty($envVar)) {
            return;
        }

        $credential = getenv($envVar);
        if (empty($credential)) {
            return;
        }
        $this->storeTransient($request->id(), $credential);
    }

    protected function hasTransient($id)
    {
        return array_key_exists($id, $this->transientCache);
    }

    protected function fetchTransient($id)
    {
        return $this->transientCache[$id];
    }

    protected function storeTransient($id, $credential)
    {
        $this->transientCache[$id] = $credential;
    }

    /**
     * Determine the path to the cache file.
     */
    protected function credentialKey($id)
    {
        if (empty($this->userId)) {
            return;
        }
        return $this->userId . '-' . $id;
    }
}
