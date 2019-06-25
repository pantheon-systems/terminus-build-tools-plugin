# Terminus Build Tools Plugin

[![CircleCI](https://circleci.com/gh/pantheon-systems/terminus-build-tools-plugin.svg?style=shield)](https://circleci.com/gh/pantheon-systems/terminus-build-tools-plugin)
[![Terminus v1.x Compatible](https://img.shields.io/badge/terminus-v1.x-green.svg)](https://github.com/pantheon-systems/terminus-build-tools-plugin/tree/1.x)

Terminus Plugin that contains a collection of commands useful during the build step on a [Pantheon](https://www.pantheon.io) site that manages its files using Composer, and uses a GitHub PR workflow with Behat tests run via Circle CI (or some other testing service). For detailed set-up instructions, see the [Terminus Build Tools Guide](https://pantheon.io/docs/guides/build-tools/). There is also a startup command that will set up and configure a new Composer-managed test site with scripts.

## Requirements

- If you are using Terminus 2, you must use the development Build Tools 2.x release
- If you are using Terminus 1, you must use the stable Build Tools 1.x release

PHP 7.2 is recommended.

### Installing Build Tools 2.x:
```
mkdir -p ~/.terminus/plugins
composer create-project -d ~/.terminus/plugins pantheon-systems/terminus-build-tools-plugin:^2.0.0-beta12
```

### Installing Build Tools 1.x:
```
mkdir -p ~/.terminus/plugins
composer create-project -d ~/.terminus/plugins pantheon-systems/terminus-build-tools-plugin:^1
```

## Setup

It is recommended that you use one of the provided example projects as a template when creating a new project. All of the example projects have been update to use Terminus 2 and the unstable Build Tools 2.x release.

The standard example repositories are each assigned an abbreviation, as shown below:

- [WordPress](https://github.com/pantheon-systems/example-wordpress-composer): wp
- [Drupal 8](https://github.com/pantheon-systems/example-drops-8-composer): d8
- [Drupal 7](https://github.com/pantheon-systems/example-drops-7-composer): d7

You can get started with one of these examples by using the `build:project:create` command:
```
$ terminus build:project:create --team='My Agency Name' wp my-site
```
This command will create:

- A Pantheon site
- A GitHub repository
- A CircleCI test configuration

It will prompt you for the credentials it needs to create these assets.

Note: After running this command, if you get an error "There are no commands defined in the "build:project" namespace," then you may need to install this Terminus plugin first as described in [Requirements](#requirements), above.

Note: It is important to specify the name of your agency organization via the `--team` option. If you do not do this, then your new site will not have the capability to create multidev environments.

## Available Services

At the moment, the build:project:create command only supports services in the following combination: 

| Git Host  | CI Service |
| --------- | ---------- |
| GitHub    | CircleCI   |
| GitLab    | GitLabCI   |
| BitBucket | CircleCI   |

Of these, only GitHub with CircleCI is complete and stable. The GitLab and BitBucket services are incomplete; see the sections below for details.

### Starting a new GitLab Project

```
$ terminus build:project:create --git=gitlab --team='My Agency Name' wp my-site
```

#### Limitations

- Commits to the Pantheon site are not pushed back to the GitLab repository

### Starting a new BitBucket Project

```
$ terminus build:project:create --git=bitbucket --team='My Agency Name' wp my-site
```

#### Limitations

- Automatic multidev deletion not working; test multidevs must be deleted manually
- Commits to the Pantheon site are not pushed back to the GitLab repository
- Comments are not added to pull requests when multidevs are created

## Customization

More starter sites will be available in the future. You may easily create your own by forking one of the standard starter sites and customizing it to suit. To use a custom starter, register your project on Packagist, and then use the projects org/name with the build:project:create command:
```
$ terminus build:project:create --team='My Agency Name' my-project/my-starter my-site
```
See [Starter Site Shortcuts](#starter-site-shortcuts) below for instructions on defining your own shortcuts for your starter projects.

Additional options are available to further customize the build:project:create command:

| Option           | Description    |
| ---------------- | -------------- |
| --pantheon-site  | The name to use for the Pantheon site (defaults to the name of the GitHub site) | 
| --team           | The Pantheon team to associate the site with |
| --org            | The GitHub org to place the repository in (defaults to authenticated user) |
| --email          | The git user email address to use when committing build results |
| --test-site-name | The name to use when installing the test site |
| --admin-password | The password to use for the admin when installing the test site |
| --admin-email    | The email address to use for the admin |
| --ci             | The CI provider to use. Defaults to "circleci" |
| --git            | The git repository provider to use. Defaults to "github" |

See `terminus help build:project:create` for more information.

### Configuration

Configuration values for the Terminus Build Tools Plugin may be stored in your Terminus Configuration file, located at `~/.terminus/config.yml`.

#### Default Values for Options

Terminus configuration is based on the [Robo PHP configuration system](http://robo.li/getting-started/#configuration). Default option values for Terminus commands can be defined in the same way as other Robo applications. For example, the options for the command `build:project:create` are stored in the section `command:` > `build:` > `project:` > `create:` > `options:`. The example below provides default values for the `--admin-password` and `--team` options. 
```
command:
  build:
    project:
      create:
        options:
          admin-password: secret-secret
          team: My Pantheon Org
```
#### Starter Site Shortcuts

If you often create sites based on certain common starter sites, you may also use your Terminus configuration file to define custom starter site shortcuts. The example below defines shortcuts for the Lightning and Contenta distributions:
```
command:
  build:
    project:
      create:
        shortcuts:
          contenta: pantheon-systems/example-drops-8-composer:dev-contenta
``` 
Note that the project name follows the standard defined by Composer: `org-name` / `project-name` : dev- `branch-name`.

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
- Add any needed cleanup steps (e.g. `drush updatedb`) after `build:env:merge`.

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
** IMPORTANT NOTE: ** If you initially set up your site using `terminus build:project:create`, and you do **not** use the `--team` option, or the team you specify is not an Agency organization, then your Circle configuration will automatically be set up to use only the dev environment. If you later add multidev capabilities to your site, you will need to [visit the Circle CI environment variables configuration page](https://circleci.com/docs/api/#authentication) and **delete** the entry for TERMINUS_ENV.

## Other Build Tools Commands

The examples below show how some of the other build:env: commands are used within test scripts. It is not usually necessary to run any of these commands directly; they may be of interest if you are customizing or building your own test scripts.

### Create Testing Multidev

`terminus build:env:create my-pantheon-site.dev ci-1234`

This command will commit the generated artifacts to a new branch and then create the requested multidev environment for use in testing.

### Push Code to an Existing Multidev

`terminus build:env:push my-pantheon-site.dev`

This command will commit the generated artifacts to an existing multidev environment, or to the dev environment.

### Merge Testing Multidev into Dev Environment

`terminus build:env:merge my-pantheon-site.ci-1234`

### Delete Testing Multidevs

`terminus build:env:delete my-pantheon-site '^ci-' --keep=2 --delete-branch`

### List Testing Multidevs

`terminus build:env:list`

## Help
Run `terminus list build` for a complete list of available commands. Use `terminus help <command>` to get help on one command.

