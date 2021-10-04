<?php
namespace Pantheon\TerminusBuildTools\Utility;

use Robo\Common\ProcessUtils;

trait ExecWithRedactionTrait
{
    protected function execWithRedaction($cmd, $replacements = [], $redacted = [])
    {
        $redactedReplacements = $this->redactedReplacements($replacements, $redacted);
        $redactions = $this->redactions($redactedReplacements, $replacements);
        $redactedCommand = $this->interpolate($cmd, $redactedReplacements + $replacements);
        $command = $this->interpolate("$cmd{redactions}", ['redactions' => $redactions] + $replacements);

        $this->logger->notice('Executing {command}', ['command' => $redactedCommand]);
        passthru($command, $result);
        if ($result != 0) {
            throw new \Exception("Command `$redactedCommand` failed with exit code $result");
        }
    }

    private function redactedReplacements($replacements, $redacted)
    {
        $result = [];
        foreach ($redacted as $key => $value) {
            if (is_numeric($key)) {
                $result[$value] = '[REDACTED]';
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    private function redactions($redactedReplacements, $replacements)
    {
        if (empty($redactedReplacements)) {
            return '';
        }

        // Make a simple array (with numeric keys) whose values
        // are the values of the items in $replacements whose keys
        // appear in $redactedReplacements.
        $values = array_map(
            function ($item) use ($replacements) {
                return $replacements[$item];
            },
            array_keys($redactedReplacements)
        );

        // If any redacted value contains a # or a ', then simply turn off output
        if ($this->unsafe($values)) {
            return ' >/dev/null 2>&1';
        }

        // Create 'sed' expressions to replace the redactions.
        $redactions = array_map(
            function ($value) {
                return "-e 's#$value#[REDACTED]#'";
            },
            $values
        );

        return ' 2>&1 | sed ' . implode(' ', $redactions);
    }

    private function unsafe($values)
    {
        foreach ($values as $value) {
            if ((strpos($value, "'") !== false) || (strpos($value, "#") !== false)) {
                return true;
            }
        }
        return false;
    }

    private function interpolate($str, array $context)
    {
        // build a replacement array with braces around the context keys
        $replace = array();
        foreach ($context as $key => $val) {
            if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                $replace[sprintf('{%s}', $key)] = $val;
                $replace[sprintf('[[%s]]', $key)] = ProcessUtils::escapeArgument($val);
            }
        }

        // interpolate replacement values into the message and return
        return strtr($str, $replace);
    }
}
