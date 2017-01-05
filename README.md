# Terminus Build Tools Plugin

[![Terminus v1.x Compatible](https://img.shields.io/badge/terminus-v1.x-green.svg)](https://github.com/pantheon-systems/terminus-build-tools-plugin/tree/1.x)

Terminus Plugin that contain a collection of commands useful during the build step on a [Pantheon](https://www.pantheon.io) site that uses a GitHub PR workflow.

An [example circle.yml file](example.circle.yml) has been provided to show how this tool should be used with CircleCI. When a test runs against a "light" repository on GitHub, the following things will happen:

- Git is configured for making clones and commits.
- Terminus 1.x is installed.
- The oldest multidev testing environments are deleted.
- A build step is fired off via `composer build-assets`.
- A new multidev environment is created for testing.
- The build artifacts are pushed up to the test environment.

When a PR is merged to the master branch, then the test PR is merged into the dev environment if the test passes.

See below for the list of supported commands. This plugin is only available for Terminus 1.x.

## Configuration

In order to use this plugin, you will need to set up a GitHub repository and a CircleCI project for the site you wish to build. Credentials will also need to be set up (to be documented).

To customize this for a specific project:

- Define necessary environment variables in the Circle project settings:
  - TERMINUS_SITE: The name of the Pantheon site that will be used in testing.
  - TERMINUS_TOKEN: A Terminus OAuth token that has write access to the terminus site specified by TERMINUS_SITE.
  - GIT_EMAIL: Used to configure the git user’s email address for commits we make.
  - GITHUB_TOKEN: Optional, if needed.
- Cusomize `dependencies:` as needed to install additional tools.
- Replace example `test:` section with commands to run your tests.
- Add any needed cleanup steps (e.g. `drush updatedb`) after `build-env:merge`.

For a more specific example, see:

- https://github.com/pantheon-systems/example-drops-8-composer

## Examples

### Create Testing Multidev

`terminus build-env:create my-pantheon-site.dev ci-1234`

### Merge Testing Multidev into Dev Environment

`terminus build-env:merge my-pantheon-site.ci-1234`

### Delete Testing Multidevs

`terminus build-env:delete my-pantheon-site '^ci-' --keep=2 --delete-branch`

### List Testing Multidevs

`terminus build-env:list`

## Installation
For help installing, see [Terminus's Wiki](https://github.com/pantheon-systems/terminus/wiki/Plugins)
```
mkdir -p ~/.terminus/plugins
composer create-project -d ~/.terminus/plugins pantheon-systems/terminus-build-tools-plugin:~1
```

## Help
Run `terminus list build-env` for a complete list of available commands. Use `terminus help <command>` to get help on one command.
