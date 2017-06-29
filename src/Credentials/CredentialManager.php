<?php

namespace Pantheon\TerminusBuildTools\Credentials;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The credential manager stores and fetches credentials from a cache.
 * When necessary, it will prompt the user to provide a needed credential.
 */
class CredentialManager implements CredentialProviderInterface
{
    protected $credentialRequests = [];
    protected $transientCache = [];
    protected $userId;

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
        $path = $this->credentialPath($id);
        return file_exists($path);
    }

    /**
     * Fetch a credential from the cache
     */
    public function fetch($id)
    {
        if ($this->hasTransient($id)) {
            return $this->fetchTransient($id);
        }
        $path = $this->credentialPath($id);
        if (empty($path)) {
            return;
        }
        $credential = file_get_contents($path);
        $credential = trim($credential);

        return $credential;
    }

    /**
     * Fetch a credential from the cache
     */
    public function store($id, $credential)
    {
        $path = $this->credentialPath($id);
        if (empty($path)) {
            return;
        }
        $credential = trim($credential);
        file_put_contents($path, $credential);
    }

    /**
     * Remove a credential from the cache
     */
    public function flush($id)
    {
        $path = $this->credentialPath($id);
        unlink($path);
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
            $credential = $this->io()->askHidden($prompt);
            $credential = trim($credential);

            if ($request->validate($credential)) {
                $this->store($request->id(), $credential);
            }
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
        $this->storeTransient($id, $credential);
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
    protected function credentialPath($id)
    {
        if (empty($this->userId)) {
            return;
        }
        // TODO: What is the best path to use here?
        return '/tmp/' . $this->userId . '/' . $id;
    }
}
