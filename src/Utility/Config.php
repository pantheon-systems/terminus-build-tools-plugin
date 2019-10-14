<?php
namespace Pantheon\TerminusBuildTools\Utility;

/**
 * Config contains utilities related to interpreting config data.
 */
class Config
{

    /**
     * Gets the COMPOSER_AUTH JSON from either an environment variable,
     * or Terminus config file.
     *
     * @param object $session
     * @return string|false
     */
    public static function getComposerAuthJson( $session )
    {
        // Prioritize a value stored in an environment variable.
        $composerAuth = $session->getConfig()->get('BUILD_TOOLS_COMPOSER_AUTH');
        // Pull value from session config (~/.terminus/config.yml) if no environment variable set.
        if (empty($composerAuth)) {
            $composerAuth = $session->getConfig()->get('build-tools.composer-auth');
        }
        // Nothing found, so bail.
        if (empty($composerAuth)) {
            return FALSE;
        }

        // Sometimes the config value is automatically extracted,
        // but we need it to remain JSON.
        if (!is_string($composerAuth)) {
            $composerAuth = json_encode($composerAuth);
        }

        // Confirm we have valid JSON.
        json_decode($composerAuth);
        if (json_last_error()) {
            return FALSE;
        }
        return $composerAuth;
    }
}
