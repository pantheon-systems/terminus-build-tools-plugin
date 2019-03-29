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
    protected $validateFn;
    protected $validationErrorMessage;
    protected $optionKey;
    protected $required;

    public function __construct(
        $id,
        $instructions = '',
        $prompt = ': ',
        $validateRegEx = '',
        $validationErrorMessage = ''
    ) {
        $this->id = $id;
        $this->instructions = $instructions;
        $this->prompt = $prompt;
        $this->validateRegEx = $validateRegEx;
        $this->validateFn = false;
        $this->validationErrorMessage = $validationErrorMessage;
        $this->optionKey = false;
        $this->required = null;
    }

    /**
     * @inheritdoc
     */
    public function id()
    {
        return $this->id;
    }

    public function optionKey()
    {
        if (!empty($this->optionKey)) {
            return $this->optionKey;
        }
        return strtolower(strtr($this->id(), '_.', '--'));
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
    public function setInstructions($instructions)
    {
        $this->instructions = $instructions;
        return $this;
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
    public function setPrompt($prompt)
    {
        $this->prompt = $prompt;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function validate($credential)
    {
        if ($this->validateFn) {
            return call_user_func($this->validateFn, $credential);
        }
        if (!empty($this->validateRegEx)) {
            return preg_match($this->validateRegEx, $credential);
        }
        return true;
    }

    public function setValidateRegEx($regex)
    {
        $this->validateRegEx = $regex;
        return $this;
    }

    public function setValidateFn(callable $fn)
    {
        $this->validateFn = $fn;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function required()
    {
        if (!isset($this->required)) {
            return !empty($this->instructions) || !empty($this->prompt);
        }
        return $this->required;
    }

    public function setRequired($isRequired)
    {
        $this->required = $isRequired;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function validationErrorMessage()
    {
        return $this->validationErrorMessage;
    }

    /**
     * @inheritdoc
     */
    public function setValidationErrorMessage($validationErrorMessage)
    {
        $this->validationErrorMessage = $validationErrorMessage;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setOptionKey($key)
    {
        $this->optionKey = $key;
        return $this;
    }
}
