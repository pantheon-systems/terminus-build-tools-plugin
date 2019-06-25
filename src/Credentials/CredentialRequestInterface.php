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
     * The key used to set the credential from the commandline. Defaults to id()
     */
    public function setOptionKey($key);

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
     * Set the instructions to display the user when a prompt is requested.
     * If empty, then the credential is optional, and will never be prompted.
     */
    public function setInstructions($instructions);

    /**
     * Prompt to display when a credential is needed.
     */
    public function prompt();

    public function setPrompt($prompt);

    /**
     * Determine whether a credential entered by the user is valid.
     */
    public function validate($credential, $otherCredentials = []);

    /**
     * Set a regex to use to validate the credential.
     */
    public function setValidateRegEx($regex);

    /**
     * Set a callback function to use to validate the credential.
     */
    public function setValidateFn(callable $fn);

    /**
     * If a credential request is required (the default), then the
     * credential manager will prompt for it if it is not provided via
     * option or environment variable.  Non-required requests may
     * be provided by environment variable or option, but are not
     * prompted for if missing.
     */
    public function required();

    /**
     * Error message to display when the credential does not validate.
     */
    public function validationErrorMessage();

    /**
     * Set the error message to display when the credential does not
     * validate. This message is displayed before re-prompting the
     * user, or thrown if for example the credential is provided
     * on the commandline or some other means in noniteractive mode.
     */
    public function setValidationErrorMessage($validationErrorMessage);

    /**
     * Return any dependent request. For example, the "username" request
     * is a dependent request of the "password" request.
     */
    public function dependentRequests();

    /**
     * Add a dependent request.
     */
    public function addDependentRequest(CredentialRequestInterface $dependentRequest);
}
