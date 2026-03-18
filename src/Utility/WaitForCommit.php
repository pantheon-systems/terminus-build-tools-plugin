<?php

namespace Pantheon\TerminusBuildTools\Utility;

/**
 * BC shim: delegates to the WaitForCommit utility in terminus core.
 *
 * This class exists to preserve backward compatibility for any code that
 * references Pantheon\TerminusBuildTools\Utility\WaitForCommit directly.
 * The canonical implementation lives in terminus core at
 * Pantheon\Terminus\Helpers\Utility\WaitForCommit.
 */
class WaitForCommit extends \Pantheon\Terminus\Helpers\Utility\WaitForCommit
{
}
