<?php
/**
 * Terminus Plugin that contain a collection of commands useful during
 * the build step on a [Pantheon](https://www.pantheon.io) site that uses
 * a GitHub PR workflow.
 *
 * See README.md for usage information.
 */

namespace Pantheon\TerminusBuildTools\Commands;

/**
 * Gitignore cut Command
 *
 * Cut gitignore in the cut mark.
 */
class GitignoreCutCommand extends BuildToolsBase
{

    /**
     * Cut gitignore in the cut mark.
     *
     * @command build:gitignore:cut
     * @aliases build:gitignore-cut
     */
    public function gitignoreCut()
    {
        $gitignore_file = getcwd() . '/.gitignore';

        if (file_exists($gitignore_file)) {
            $gitignore_contents = file_get_contents($gitignore_file);
            $gitignore_contents = preg_replace('/.*#\s?:+\s?cut\s?:+/s', '', $gitignore_contents);
            file_put_contents($gitignore_file, $gitignore_contents);
            $this->log()->notice('.gitignore cut done.');

       }
        else {
            $this->log()->warning('.gitignore file not found. Nothing to do.');
        }
    }
}
