<?php

namespace Pantheon\TerminusBuildTools\Credentials;

/**
 * Requests an oauth token for Circle CI.
 */
class CredentialRequest implements CredentialRequestInterface
{
    protected $id;
    protected $instructions;
    protected $prompt;
    protected $validateRegEx;
    protected $validationErrorMessage;

    public function __construct(
        $id,
        $instructions,
        $prompt,
        $validateRegEx,
        $validationErrorMessage
    ) {
        $this->id = $id;
        $this->instructions = $instructions;
        $this->prompt = $prompt;
        $this->validateRegEx = $validateRegEx;
        $this->validationErrorMessage = $validationErrorMessage;
    }

    /**
     * @inheritdoc
     */
    public function id()
    {
        return $this->id;
    }

    /**
     * @inheritdoc
     */
    public function environmentVariable()
    {
        return strtoupper(strtr($this->id(), '-.', '__'));
    }

    /**
     * @inheritdoc
     */
    public function instructions()
    {
        return $this->instructions;
    }

    /**
     * @inheritdoc
     */
    public function prompt()
    {
        return $this->prompt;
    }

    /**
     * @inheritdoc
     */
    public function validate($credential)
    {
        return preg_match($this->validateRegEx, $credential);
    }

    /**
     * @inheritdoc
     */
    public function validationErrorMessage()
    {
        return $this->validationErrorMessage;
    }
}
