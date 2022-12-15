<?php

namespace Pantheon\TerminusBuildTools\Credentials;

use Symfony\Component\Console\Style\SymfonyStyle;
use Pantheon\Terminus\DataStore\DataStoreInterface;
use Symfony\Component\Console\Input\InputInterface;

/**
 * The credential manager stores and fetches credentials from a cache.
 * When necessary, it will prompt the user to provide a needed credential.
 */
class CredentialManager implements CredentialProviderInterface
{
    protected $credentialRequests = [];
    protected $transientCache = [];
    protected $userId;
    protected $cache;

    public function __construct(DataStoreInterface $storage)
    {
        $this->cache = $storage;
    }

    public function clearCache()
    {
        $this->transientCache = [];
        foreach ($this->cache->keys() as $key) {
            $this->cache->remove($key);
        }
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
        $this->credentialRequests[$request->id()] = $request;
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
     * Determine whether we should ask for a credential.
     *
     * If we DO NOT HAVE the credential cached and it is required,
     * then ask for it.
     *
     * If we DO HAVE the credential cached, but it is not valid,
     * then ask for it (regardless of whether it is required).
     */
    public function shouldAsk($request)
    {
        $id = $request->id();
        if (!$this->has($id)) {
            return $request->required();
        }
        $credential = $this->fetch($id);
        return !$request->validate($credential, $this->dependentCredentials($request));
    }

    /**
     * If a credential has dependent requests, then fetch the
     * cached credential value for each and provide it as
     * auxiliary data for the validate function.
     */
    public function dependentCredentials($request)
    {
        $credentials = [];

        foreach ($request->dependentRequests() as $dependentRequest) {
            $id = $dependentRequest->id();
            $credentials[$id] = $this->fetch($id);
        }

        return $credentials;
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
        $credential = trim($credential ?? '');
        $this->storeTransient($id, $credential);

        return $credential;
    }

    /**
     * Store a credential in the cache
     */
    public function store($id, $credential)
    {
        $credential = trim($credential ?? '');
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
        $this->removeTransient($id);
        $key = $this->credentialKey($id);
        if (!empty($key)) {
            $this->cache->remove($key);
        }
    }

    /**
     * If any of the credenitals were provided via commandline options,
     * insert their values into the cache.
     */
    public function setFromOptions(InputInterface $input)
    {
        foreach ($this->credentialRequests as $request) {
            if ($input->hasOption($request->optionKey())) {
                $value = $input->getOption($request->optionKey(), '');
                if (!empty($value)) {
                    $this->store($request->id(), $value);
                }
            }
        }
    }

    /**
     * Clear everything from the credential cache. Re-apply environment
     * variables.
     */
    public function clearAll()
    {
        foreach ($this->credentialRequests as $request) {
            $this->remove($request->id());
            $this->getEnvironmentVariableIfAvailable($request);
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
            if ($this->shouldAsk($request)) {
                $instructions = $request->instructions();
                $io->write($instructions);
                $this->askOne($request, $io);
            }
        }
    }

    protected function askOne(CredentialRequestInterface $request, SymfonyStyle $io)
    {
        $prompt = $request->prompt();

        while (true) {
            $io->write("\n\n");
            $credential = $io->askHidden($prompt);
            $credential = trim($credential ?? '');

            // If the credential validates, set it and return. Otherwise
            // we'll ask again.
            if ($this->validateOne($request, $io, $credential)) {
                $this->store($request->id(), $credential);
                return;
            }

            // If this request has any dependent requests, re-ask
            // each dependent request on validation failure. For
            // example, a username / password credential pair will
            // not validate when the username is prompted, but will
            // validate once the password is entered. If the
            // username/password pair does not validate, then the
            // username will be re-prompted here so that it may be
            // corrected if necessary.
            foreach ($request->dependentRequests() as $dependentRequest) {
                $this->askOne($dependentRequest, $io);
            }
        }
    }

    /**
     * Validate a credential request. If it validates, return true;
     * otherwise, print the validation error message and return false.
     */
    protected function validateOne(CredentialRequestInterface $request, SymfonyStyle $io, $credential)
    {
        if (!$request->validateViaRegEx($credential)) {
            $io->write($request->validationErrorMessage());
            return false;
        }
        if (!$request->validateViaCallback($credential, $this->dependentCredentials($request))) {
            $io->write($request->validationCallbackErrorMessage());
            return false;
        }
        return true;
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

    protected function removeTransient($id)
    {
        unset($this->transientCache[$id]);
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
