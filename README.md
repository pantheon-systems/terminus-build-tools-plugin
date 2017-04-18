# Terminus Build Tools Plugin

[![CircleCI](https://circleci.com/gh/pantheon-systems/terminus-build-tools-plugin.svg?style=shield)](https://circleci.com/gh/pantheon-systems/terminus-build-tools-plugin)
[![Terminus v1.x Compatible](https://img.shields.io/badge/terminus-v1.x-green.svg)](https://github.com/pantheon-systems/terminus-build-tools-plugin/tree/1.x)

Terminus Plugin that contains a collection of commands useful during the build step on a [Pantheon](https://www.pantheon.io) site that manages its files using Composer, and uses a GitHub PR workflow with Behat tests run via Circle CI (or some other testing service).

An [example circle.yml file](example.circle.yml) has been provided to show how this tool should be used with CircleCI. When a test runs against a "canonical" repository on GitHub, the following things will happen:

- Git is configured for making clones and commits.
- Terminus 1.x is installed.
- The oldest multidev testing environments are deleted.
- A build step is fired off via `composer build-assets`.
- A new multidev environment is created for testing.
- The build artifacts are pushed up to the test environment.

The multidev environment created to test the pull request persists until the pull request is merged. Pantheon on-server development (SFTP) mode may be used as usual on these environments; any commits made on the Pantheon dashboard will be pushed back to the GitHub repository on the PR branch. Once the PR is merged to the master branch, then the corresponding multidev environment is also merged into the Pantheon dev environment. When using this workflow, all work is done in pull requests; the dev environment is never used for development.

See below for the list of supported commands. This plugin is only available for Terminus 1.x.

## Configuration

In order to use this plugin, you will need to set up a GitHub repository and a CircleCI project for the site you wish to build. Credentials also need to be set up. Most of the work can be done for you automatically using the New Project Quickstart below, or you may set everything up manually.

### Credentials

In order to use the build-env:create-project command, the first thing that you need to do is set up credentials to access GitHub and Circle CI. Instructions on creating these credentials can be found on the pages listed below:

- GitHub: https://help.github.com/articles/creating-an-access-token-for-command-line-use/
- Circle CI: https://circleci.com/account/api

The GitHub token needs the "repo" and "delete repo" scopes.

These credentials may be exported as environment variables. For example:
```
#!/bin/bash
export GITHUB_TOKEN=[REDACTED]
export CIRCLE_TOKEN=[REDACTED]
```
If you do not export these environment variables, you will be prompted to enter them when you run the build-env:create-project command.

### New Project Quickstart

EXPERIMENTAL: The build-env:create-project is in beta. Backwards compatibility not guarenteed until version 1.4.0.

To create a new project consisting of a GitHub project, a Pantheon site, and Circle CI tests, first set up credentials as shown in the previous section, and then run the `build-env:create-project` command as shown below:
```
terminus build-env:create-project --team="Agency Org Name" d8 example-site
```

This single command will:

- Create a new GitHub repository named `example-site`, cloned from the started site repository.
- Create a new Pantheon site built from the GitHub repository.
- Install the specified CMS and commit the exported configuration to the GitHub repository.
- Configure CircleCI to run Behat tests on the site on every pull request.
- Configure credentials on all of these services to allow the test scripts to run.

Note that it is important to specify the name of your agency organization via the `--team` option. If you do not do this, then your new site will not have the capability to create multidev environments. In this instance, all of your tests will run on the dev environment. See [Running Tests without Multidevs](#running-tests-without-multidevs), below, for more information.

In the example above, the parameter `d8` is shorthand for the project `pantheon-systems/example-drops-8-composer`, the canonical Composer-managed Drupal 8 site for Pantheon. You may replace this parameter with the GitHub organization and project name for any other canonical starter site that you would like to use.

| Starter Site | Shorthand | Packagist Project Name                    |
| ------------ | --------- | ----------------------------------------- |
| Drupal 8     | d8        | [pantheon-systems/example-drops-8-composer](https://github.com/pantheon-systems/example-drops-8-composer) |
| Drupal 7     | d7        | [pantheon-systems/example-drops-7-composer](https://github.com/pantheon-systems/example-drops-7-composer) |

More starter sites will be available in the future. You may easily create your own by following the example of the existing starter site, and publishing your customized version on Packagist. At the moment, there is no way to extend the list of shorthand site names, though.

Additional options are available to further customize the build-env:create-project command:

| Option           | Description    |
| ---------------- | -------------- |
| --pantheon-site  | The name to use for the Pantheon site (defaults to the name of the GitHub site) | 
| --team           | The Pantheon team to associate the site with |
| --org            | The GitHub org to place the repository in (defaults to authenticated user) |
| --email          | The git user email address to use when committing build results |
| --test-site-name | The name to use when installing the test site |
| --admin-password | The password to use for the admin when installing the test site |
| --admin-email    | The email address to use for the admin |

See `terminus help build-env:create-project` for more information.

### Build Customizations

To customize this for a specific project:

- Define necessary environment variables in the Circle project settings file `circle.yml`:
  - TERMINUS_SITE: The name of the Pantheon site that will be used in testing.
  - TERMINUS_TOKEN: A Terminus OAuth token that has write access to the terminus site specified by TERMINUS_SITE.
  - GIT_EMAIL: Used to configure the git user’s email address for commits we make.
  - GITHUB_TOKEN: Optional, if needed.
- Customize `dependencies:` as needed to install additional tools.
- Replace example `test:` section with commands to run your tests.
- [Add a `build-assets` script](https://pantheon.io/blog/writing-composer-scripts) to your composer.json file.
- Add any needed cleanup steps (e.g. `drush updatedb`) after `build-env:merge`.

### PR Environments vs Other Test Environments

Note that using a single environment for each PR means that it is not possible to run multiple tests against the same PR at the same time. Currently, no effort is made to cancel running tests when a new one is kicked off; if the concurrent build is not cancelled before a new commit is pushed to the PR branch, then the two tests could potentially conflict with each other. If support for parallel tests on the same PR is desired, then it is possible to eliminate PR environments, and make all tests run in their own independent CI environment. To do this, make the following change in the environments section of the circle.yml file:
```
    TERMINUS_ENV: $CI_LABEL
```
### Running Tests without Multidevs

To use this tool on a Pantheon site that does not have multidev environments support, it is possible to run all tests against the dev environment. If this is done, then clearly it is not possible to run multiple tests at the same time. To use the dev environment, make the following change in the environments section of the circle.yml file:
```
    TERMINUS_ENV: dev
```
** IMPORTANT NOTE: ** If you initially set up your site using `terminus build-env:create-project`, and you do **not** use the `--team` option, or the team you specify is not an Agency organization, then your Circle configuration will automatically be set up to use only the dev environment. If you later add multidev capabilities to your site, you will need to [visit the Circle CI environment variables configuration page](https://circleci.com/docs/api/#authentication) and **delete** the entry for TERMINUS_ENV.

## Examples

The examples below show how some of the other build-env commands are used within test scripts. It is not usually necessary to run any of these commands directly.

### Create Testing Multidev

`terminus build-env:create my-pantheon-site.dev ci-1234`

This command will commit the generated artifacts to a new branch and then create the requested multidev environment for use in testing.

### Push Code to an Existing Multidev

`terminus build-env:push-code my-pantheon-site.dev`

This command will commit the generated artifacts to an existing multidev environment, or to the dev environment.

### Merge Testing Multidev into Dev Environment

`terminus build-env:merge my-pantheon-site.ci-1234`

### Delete Testing Multidevs

`terminus build-env:delete my-pantheon-site '^ci-' --keep=2 --delete-branch`

### List Testing Multidevs

`terminus build-env:list`

## Installation
For help installing, see [Manage Plugins](https://pantheon.io/docs/terminus/plugins/)
```
mkdir -p ~/.terminus/plugins
composer create-project -d ~/.terminus/plugins pantheon-systems/terminus-build-tools-plugin:~1
```

## Help
Run `terminus list build-env` for a complete list of available commands. Use `terminus help <command>` to get help on one command.
