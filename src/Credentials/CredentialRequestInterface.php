<?php

namespace Pantheon\TerminusBuildTools\Credentials;

/**
 * A credential request represents a credential needed by a provider.
 *
 * The credential manager will use credential requests to determine
 * what needs to be obtained, and will either pull it from the cache
 * or prompt the user for it.
 */
interface CredentialRequestInterface
{
    /**
     * The identifier used to store the credential in the cache et. al.
     */
    public function id();

    /**
     * The environment variable name that may be used to provide the credential
     */
    public function environmentVariable();

    /**
     * Instructions for the user on how to generate a credential when
     * a new one is needed.
     */
    public function instructions();

    /**
     * Prompt to display when a credential is needed.
     */
    public function prompt();

    /**
     * Determine whether a credential entered by the user is valid.
     */
    public function validate($credential);

    /**
     * Error message to display when the credential does not validate.
     */
    public function validationErrorMessage();
}
