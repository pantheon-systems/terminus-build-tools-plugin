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

Testing multidev environments are divided into two groups: environments used for testing pull requests, and environments used for testing other kinds of builds (e.g. tagged releases, commits or merges to master, and so on). PR test environments persist until the PR branch on GitHub is deleted. The other test environments are deleted just before a new testing environment is created. The most recent three of these environments remain, and the rest are deleted.

See below for the list of supported commands. This plugin is only available for Terminus 1.x.

## Configuration

In order to use this plugin, you will need to set up a GitHub repository and a CircleCI project for the site you wish to build. Credentials will also need to be set up (to be documented).

### Build Customizations

To customize this for a specific project:

- Define necessary environment variables in the Circle project settings:
  - TERMINUS_SITE: The name of the Pantheon site that will be used in testing.
  - TERMINUS_TOKEN: A Terminus OAuth token that has write access to the terminus site specified by TERMINUS_SITE.
  - GIT_EMAIL: Used to configure the git user’s email address for commits we make.
  - GITHUB_TOKEN: Optional, if needed.
- Customize `dependencies:` as needed to install additional tools.
- Replace example `test:` section with commands to run your tests.
- [Add a `build-assets` script](https://pantheon.io/blog/writing-composer-scripts) to your composer.json file.
- Add any needed cleanup steps (e.g. `drush updatedb`) after `build-env:merge`.

### Specific Examples

For a more specific example, see:

- https://github.com/pantheon-systems/example-drops-8-composer

### PR Environments vs Other Test Environments

Note that using a single environment for each PR means that it is not possible to run multiple tests against the same PR at the same time. Currently, no effort is made to cancel running tests when a new one is kicked off; if the concurrent build is not cancelled before a new commit is pushed to the PR branch, then the two tests could potentially conflict with each other. If support for parallel tests on the same PR is desired, then it is possible to eliminate PR environments, and make all tests run in their own independent CI environment. To do this, make the following change in the environments section of the circle.yml file:

    TERMINUS_ENV: $CI_LABEL

### Running Tests without Multidevs

To use this tool on a Pantheon site that does not have multidev environments support, it is possible to run all tests against the dev environment. If this is done, then clearly it is not possible to run multiple tests at the same time. To use the dev environment, make the following change in the environments section of the circle.yml file:

    TERMINUS_ENV: dev

## Examples

### Create Testing Multidev

`terminus build-env:create my-pantheon-site.dev ci-1234`

This command will commit the generated artifacts to a new branch and then create the requested multidev environment for use in testing. Note that it is very important that the .gitignore file allow the build artifacts to be added to the  repository. If the build artifacts are normally included in the .gitignore file (e.g. to keep them from being added to the GitHub repository), then the .gitignore file should be modified during the build step to remove any entry that excludes artifacts. Modifications to the .gitignore file will *not* be included in the commit, so the resulting multidev environment will ignore any changes made to the build artifacts when making commits on the Pantheon site during on-server development.

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
