# Terminus Build Tools Plugin

[![CircleCI](https://circleci.com/gh/pantheon-systems/terminus-build-tools-plugin.svg?style=shield)](https://circleci.com/gh/pantheon-systems/terminus-build-tools-plugin)
[![Terminus v2.x Compatible](https://img.shields.io/badge/terminus-v2.x-green.svg)](https://github.com/pantheon-systems/terminus-build-tools-plugin/tree/2.x)
[![Terminus v3.x Compatible](https://img.shields.io/badge/terminus-v3.x-green.svg)](https://github.com/pantheon-systems/terminus-build-tools-plugin/tree/3.x)
[![Actively Maintained](https://img.shields.io/badge/Pantheon-Actively_Maintained-yellow?logo=pantheon&color=FFDC28)](https://pantheon.io/docs/oss-support-levels#actively-maintained-support)

Build Tools is a Terminus Plugin that contains a collection of commands useful for projects making use of an external Git provider and Continuous Integration (CI) along with [Pantheon](https://www.pantheon.io).

## Table of Contents

1. [Project Purpose](#project-purpose)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Setup](#setup)
5. [Available Services](#available-services)
6. [Commands](#commands)
7. [Customization](#customization)
8. [Build Tools Command Examples](#build-tools-command-examples)
9. [Help](#help)
10. [Related Repositories](#related-repositories)

## Project Purpose
The main purposes of the Build Tools project are to:

**Ease the creation of new projects making use of an external Git provider, a Continuous Integration service, and Pantheon.**
This is primarily done through the [`build:project:create` commands](#buildprojectcreate), which scaffolds new projects from a [template repository](#template-repositories) and performs one-time setup, such as configuring SSH keys and environment variables, needed to connect an external Git provider and CI service with Pantheon. For detailed set-up instructions, see the [Terminus Build Tools Guide](https://pantheon.io/docs/guides/build-tools/). To use your own template repository see [Customization](#customization).

**Add additional commands to Terminus to make tasks common in an automated CI workflow easier.**
See [Commands](#commands) and [Build Tools Command Examples](#build-tools-command-examples) for details.

## Requirements

- If you are using Terminus 3, you must use the [Build Tools `3.x` release](https://github.com/pantheon-systems/terminus-build-tools-plugin/tree/3.x).
- If you are using Terminus 2, you must use the [Build Tools `2.x` release](https://github.com/pantheon-systems/terminus-build-tools-plugin/tree/2.x).

PHP `7.2` or greater is recommended.

## Installation

### Installing Build Tools 3.x:

```
terminus self:plugin:install terminus-build-tools-plugin
```

### Installing Build Tools 2.x:

```
mkdir -p ~/.terminus/plugins
composer create-project --no-dev -d ~/.terminus/plugins pantheon-systems/terminus-build-tools-plugin:^2
```

#### Note about dev dependencies

The Terminus Build Tools plugin should be installed **without** dev dependencies. If you install the plugin with a different method, such as cloning this source repository, use `composer install --no-dev` to download the project dependencies.

## Setup

It is recommended that you use one of the provided example projects as a template when creating a new project. All of the example projects use Terminus `3` and Build Tools `3.x`.

The default template repositories are each assigned an abbreviation, as shown below:

- [WordPress](https://github.com/pantheon-systems/example-wordpress-composer): `wp`
- [Drupal 9](https://github.com/pantheon-upstreams/drupal-composer-managed): `d9`
- [Drupal 8](https://github.com/pantheon-systems/example-drops-8-composer): `d8`
- [Drupal 7](https://github.com/pantheon-systems/example-drops-7-composer): `d7`

More details about these template repositories see [Template Repositories](#template-repositories) in this document or visit the links above.

You can get started with one of these examples by using the `build:project:create` command:
```
$ terminus build:project:create --team='My Agency Name' wp my-site
```
This command will create:

- A Pantheon site
- A GitHub repository
- A CircleCI test configuration

It will prompt you for the credentials it needs to create these assets. While GitHub and CircleCI are the defaults, other providers are supported as well. See [available services](#available-services) for details.

Note: After running this command, if you get an error "There are no commands defined in the "build:project" namespace", then you may need to install this Terminus plugin first as described in [Requirements](#requirements), above.

Note: It is important to specify the name of your agency organization via the `--team` option. If you do not do this, then your new site will be associated with your user and will not have the capability to create multidev environments.

## Available Services

The `build:project:create` command supports services in the following combination:

| Git Host  | CI Service       |
| --------- | ----------       |
| GitHub    | CircleCI         |
| GitHub    | Github Actions   |
| GitLab    | GitLabCI         |
| BitBucket | CircleCI         |

Note: if using Github Actions, token should have the "workflow" scope.

### Starting a new GitLab Project

```
$ terminus build:project:create --git=gitlab --team='My Agency Name' wp my-site
```

### Starting a new BitBucket Project

```
$ terminus build:project:create --git=bitbucket --team='My Agency Name' wp my-site
```

### Starting a new Github/Github Actions Project

```
$ terminus build:project:create --ci=githubactions --team='My Agency Name' wp my-site
```

#### Limitations

**Bitbucket**
- Composer Lock Updater isn't working quite yet.

## Commands

The following commands are available as part of the Build Tools plugin.

### build:project:create

The `build:project:create` command is used to initialize projects within the Git PR workflow. Automated setup of the Pantheon website along with the corresponding Git and CI provider is included.

#### Command Options

Additional options are available to further customize the `build:project:create` command:

 | Option                | Description    |
 | --------------------- | -------------- |
 | --pantheon-site       | The name to use for the Pantheon site (defaults to the name of the Git site) |
 | --team                | The Pantheon team to associate the site with |
 | --org                 | The Git organization to place the repository in (defaults to authenticated user) |
 | --label               | The friendly name to use for the Pantheon site (defaults to the name of the Git site) |
 | --email               | The git user email address to use when committing build results |
 | --test-site-name      | The name to use when installing the test site |
 | --admin-password      | The password to use for the admin when installing the test site |
 | --admin-email         | The email address to use for the admin |
 | --admin-username      | The username to use for the admin |
 | --stability           | The stability to use with composer when creating the project (defaults to dev) |
 | --keep                | The ability to keep a project repository cloned after your project is created |
 | --use-ssh             | The ability to perform the initial git push to the repository provider over SSH instead of HTTPS |
 | --ci                  | The CI provider to use. Defaults to "circleci" |
 | --git                 | The git repository provider to use. Defaults to "github" |
 | --visibility          | The visibility of the project. Defaults to "public". Use "public" or "private" for GitHub and "public", "private", or "internal" for GitLab |
 | --region              | The region to create the site in. See [the Pantheon regions documentation](https://pantheon.io/docs/regions#create-a-new-site-in-a-specific-region-using-terminus) for details. |
 | --template-repository | Private composer repository to download template or git url if using the expanded version when no composer repository. |
 | --ci-template | Git repo that contains the CI scripts that will be copied if there is no ci in the source project. |


If you want to use a private composer repository, you should provide the credentials like this:

```
export TERMINUS_BUILD_TOOLS_COMPOSER_AUTH=json_encoded_string
```

or in ~/.terminus/config.yml file under build-tools.composer-auth.

Then, in the build:project:create command, pass a composer-repository option like this:

```
terminus build:project:create --template-repository="https://repo.packagist.com/myorg" myorg/myrepo my-project
```

If you want to use git repository that has not been published to packagist as your template, you should do it like this:

```
terminus build:project:create --template-repository="git@github.com:myorg/myrepo.git" myorg/myrepo-template my-project
```

The package name in the composer.json file into the template repo should be "myorg/myrepo-template". If myorg/myrepo is a private repo, you should have access to it in your current terminal.

You can also use the following shorthand:

```
terminus build:project:create git@github.com:myorg/myrepo.git my-project
```

and build tools will figure out the right package name for you.

You can find more info about [composer repositories](https://getcomposer.org/doc/05-repositories.md), [private packages](https://getcomposer.org/doc/articles/handling-private-packages.md), [cli authentication](https://getcomposer.org/doc/03-cli.md#composer-auth) and [authentication methods](https://getcomposer.org/doc/articles/authentication-for-private-packages.md) in the official [composer documentation](https://getcomposer.org/doc/).

See `terminus help build:project:create` for more information.

### build:project:repair

The `build:project:repair` command is used to repair projects that were created with the Build Tools plugin. This is useful for rotating credentials, such as provider authentication tokens.

#### Command Options

Additional options are available to further customize the `build:project:repair` command:

 | Option           | Description      |
 | ---------------- | ---------------- |
 | --env            | The environment variables you would like to set on the CI system |

### build:comment:add:commit

The `build:comment:add:commit` command is used to add a comment to a commit on the Git Provider. This is useful in CI scripts for commenting as multidev environments are created or other code feedback is determined.

Either the `--message` and/or the `--site_url` options are required.

#### Command Options

Additional options are available to customize the `build:comment:add:commit` command:

 | Option           | Description      |
 | ---------------- | ---------------- |
 | --sha            | The SHA hash of the commit to add the comment to |
 | --message        | The message to post to the commit |
 | --site_url       | If provided, will include a "Visit Site" link at the start of the comment, linking to the provided site URL |

### terminus build:comment:add:pr

The `build:comment:add:pr` command is used to add a comment to a pull request on the Git Provider. This is useful in CI scripts for commenting as multidev environments are created or other code feedback is determined.

The `--pr_id` option and either the `--message` and/or the `--site_url` options are required.

#### Command Options

Additional options are available to customize the `build:comment:add:pr` command:

 | Option           | Description      |
 | ---------------- | ---------------- |
 | --pr_id          | Required. The number of the pull request to add the comment to |
 | --message        | The message to post to the pull request |
 | --site_url       | If provided, will include a "Visit Site" link at the start of the pull request, linking to the provided site URL |

### build:credentials:clear

The `build:credentials:clear` command is available to clear cached credentials from Build Tools. This is useful when developing Build Tools or trying to remove credentials from a machine.

#### Command Options

There are no additional options for this command.

### build:env:create

The `build:env:create` command creates the specified multidev environment on the given Pantheon site from the build assets at the current working directory.

#### Command Options

 | Option           | Description      |
 | ---------------- | ---------------- |
 | --label          | The name of the environment in commit comments |
 | --clone-content  | Clone the content from the dev environment to the new multidev environment |
 | --db-only        | When cloning content, whether to only clone the database (by default, both the database and files are cloned |
 | --message        | The commit message to use when committing the built assets to Pantheon |
 | --no-git-force   | Set this flag to omit the --force flag from `git add` and `git push` |

By default, this command uses the `--force` flag for both `git add` and `git push`. Passing `--no-git-force` will prevent adding this flag but unless your remotes are in sync, it will most likely make the push fail.

### build:env:delete:ci

The `build:env:delete:ci` command is used to delete multidev environments on Pantheon that match the CI pattern of builds (`ci-*`).

#### Command Options

 | Option           | Description      |
 | ---------------- | ---------------- |
 | --keep           | The number of environments matching the pattern to keep |
 | --dry-run        | If set, this command only determines which environments should be deleted but doesn't actually delete them |

### build:env:delete:pr

The `build:env:delete:pr` command is used to delete multidev environments on Pantheon that match the PR pattern of builds (`pr-*`) for pull requests (GitHub and BitBucket) or merge requests (GitLab) that have been closed.

#### Command Options

 | Option           | Description      |
 | ---------------- | ---------------- |
 | --dry-run        | If set, this command only determines which environments should be deleted but doesn't actually delete them |

### build:env:install

The `build:env:install` command is used to install the CMS on a Pantheon site the specified site.

#### Command Options

 | Option           | Description      |
 | ---------------- | ---------------- |
 | --account-mail   | The email address for the first user account created during install |
 | --account-name   | The username for the first user account created during install |
 | --account-pass   | The password for the first user account created during install |
 | --site-mail      | The email address used for the CMS |
 | --site-name      | The name of the site to be set within the CMS |

### build:env:list

The `build:env:list` command is used to list the multidev environments in the specified site.

#### Command Options

There are no additional options for this command.

### build:env:merge

The `build:env:merge` command merges a multidev environment in Pantheon into the dev environment.

#### Command Options

 | Option           | Description      |
 | ---------------- | ---------------- |
 | --label          | The name of the environment when referred to in commit comments |
 | --delete         | Whether or not to delete the multidev environment after it is merged |

### build:env:obliterate

The `build:env:obliterate` command deletes a project that was set up through the `build:project:create` workflow. This includes the Pantheon site as well as the Git provider repository and the CI provider project.

Note: this is a destructive, irreversible command that should be used with caution.

#### Command Options

There are no additional command options for this command.

### build:env:push

The `build:env:push` command pushes code in the current directory to an existing Pantheon site/environment.

#### Command Options

 | Option           | Description      |
 | ---------------- | ---------------- |
 | --label          | The name of the site when referred to in commit comments. |
 | --message        | The commit message to use when committing built code to Pantheon |
 | --no-git-force   | Set this flag to omit the --force flag from `git add` and `git push` |

 By default, this command uses the `--force` flag for both `git add` and `git push`. Passing `--no-git-force` will prevent adding this flag but unless your remotes are in sync, it will most likely make the push fail.

### build:project:info

The `build:project:info` command displays information about a site created by the `build:project:create` command.

#### Command Options

There are no additional command options for this command.

### build:secrets:delete

The `build:secrets:delete` command deletes a secret from Pantheon. These secrets are commonly used for storing information needed by CI integrations, such as [Quicksilver Pushback](https://www.github.com/pantheon-systems/quicksilver-pushback).

#### Command Options

 | Option           | Description      |
 | ---------------- | ---------------- |
 | --file           | The name of the file to use for storing the secret. Defaults to tokens.json |

### build:secrets:list

The `build:secrets:list` command lists all secret from Pantheon. These secrets are commonly used for storing information needed by future CI integration such as [Quicksilver Pushback](https://www.github.com/pantheon-systems/quicksilver-pushback).

#### Command Options

 | Option           | Description      |
 | ---------------- | ---------------- |
 | --file           | The name of the file to use for storing the secret. Defaults to tokens.json |

### build:secrets:set

The `build:secrets:set` command sets a secret in a Pantheon. These secrets are commonly used for storing information needed by future CI integration such as [Quicksilver Pushback](https://www.github.com/pantheon-systems/quicksilver-pushback).

#### Command Options

 | Option           | Description      |
 | ---------------- | ---------------- |
 | --file           | The name of the file to use for storing the secret. Defaults to tokens.json |
 | --clear          | If set, will overwrite a secret with the existing name |
 | --skip-if-empty  | If set, will not write anything if the value passed to the command is empty |

### build:secrets:show

The `build:secrets:show` command shows a secret from Pantheon. These secrets are commonly used for storing information needed by CI integrations, such as [Quicksilver Pushback](https://www.github.com/pantheon-systems/quicksilver-pushback).

#### Command Options

 | Option           | Description      |
 | ---------------- | ---------------- |
 | --file           | The name of the file to use for storing the secret. Defaults to tokens.json |

### build:workflow:wait

The `build:workflow:wait` command waits for a workflow in Pantheon to complete before returning. This is useful when waiting for code to be deployed to a Pantheon environment.

#### Command Options

 | Option           | Description      |
 | ---------------- | ---------------- |
 | --start          | The time to ignore workflow operations before |
 | --max            | The maximum amount of time to wait for a workflow to complete |

### build:gitignore:cut

The `build:gitignore:cut` command cuts your .gitignore file in the cut line. This is useful before pushing to Pantheon from a source repo.


## Customization

You may easily create your own project template by forking one of the Pantheon maintained examples (linked above) and customizing it to suit your needs. To use a custom starter, register your project on Packagist, and then use the projects org/name with the `build:project:create` command:
```
$ terminus build:project:create --team='My Agency Name' my-project/my-starter my-site
```
See [Starter Site Shortcuts](#starter-site-shortcuts) below for instructions on defining your own shortcuts for your starter projects.


### Configuration

Configuration values for the Terminus Build Tools Plugin may be stored in your Terminus Configuration file, located at `~/.terminus/config.yml`. This is especially useful for agencies who would like every site created within their Pantheon team.

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

#### Self-Hosted GitLab

The GitLab URL used by Build Tools can be defined by updating the `build-tools:provider:git:gitlab:url` configuration value, as demonstrated by the example below. Note that you will need to replace `hostname` with the actual GitLab instance hostname.

```
build-tools:
  provider:
    git:
      gitlab:
        url: hostname
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

- Define necessary environment variables within your CI provider:
  - TERMINUS_SITE: The name of the Pantheon site that will be used in testing.
  - TERMINUS_TOKEN: A Terminus OAuth token that has write access to the terminus site specified by TERMINUS_SITE.
  - GIT_EMAIL: Used to configure the git user’s email address for commits we make.
- Customize `dependencies:` as needed to install additional tools.
- Replace example `test:` section with commands to run your tests.
- [Add a `build-assets` script](https://pantheon.io/blog/writing-composer-scripts) to your composer.json file.

### PR Environments vs Other Test Environments

Note that using a single environment for each PR means that it is not possible to run multiple tests against the same PR at the same time. Currently, no effort is made to cancel running tests when a new one is kicked off; if the concurrent build is not cancelled before a new commit is pushed to the PR branch, then the two tests could potentially conflict with each other. If support for parallel tests on the same PR is desired, then it is possible to eliminate PR environments, and make all tests run in their own independent CI environment. To do this, configure your CI provider by **adding** the following environment variable:
```
    TERMINUS_ENV: $CI_LABEL
```
### Running Tests without Multidevs

To use this tool on a Pantheon site that does not have multidev environments support, it is possible to run all tests against the `dev` environment. If this is done, then it is not possible to run multiple tests at the same time. To use the `dev` environment, configure your CI provider by **adding** the following environment variable:
```
    TERMINUS_ENV: dev
```
** IMPORTANT NOTE: ** If you initially set up your site using `terminus build:project:create`, and you do **not** use the `--team` option, or the team you specify is not an Agency organization, then your configuration will automatically be set up to use only the dev environment. If you later add multidev capabilities to your site, you will need to edit the environment variables in your CI configuration and **delete** the entry for `TERMINUS_ENV`.

## Build Tools Command Examples

The examples below show how some of the other `build:env:` commands are used within test scripts. It is not usually necessary to run any of these commands directly; they may be of interest if you are customizing or building your own test scripts.

### Create Testing Multidev

`terminus build:env:create my-pantheon-site.dev ci-1234`

This command will commit the generated artifacts to a new branch and then create the requested multidev environment for use in testing.

### Push Code to the Dev Environment

`terminus build:env:push my-pantheon-site.dev`

This command will commit the generated artifacts to an existing multidev environment, or to the dev environment.

### Merge Testing Multidev into Dev Environment

`terminus build:env:merge my-pantheon-site.ci-1234`

### Delete Testing Multidevs

`terminus build:env:delete my-pantheon-site '^ci-' --keep=2 --delete-branch`

### List Testing Multidevs

`terminus build:env:list`

### Commenting on a pull request or merge request

`terminus build:comment:add:pr --pr_number=123 --message="Tests passed!"`

## Help
Run `terminus list build` for a complete list of available commands. Use `terminus help <command>` to get help on one command.

## Related Repositories

### Template Repositories

In addition to the Terminus Build Tools Plugin, Pantheon maintains template repositories for:

- [WordPress](https://github.com/pantheon-systems/example-wordpress-composer)
- [Drupal 9](https://github.com/pantheon-upstreams/drupal-composer-managed)
- [Drupal 8](https://github.com/pantheon-systems/example-drops-8-composer)
- [Drupal 7](https://github.com/pantheon-systems/example-drops-7-composer)

Each repository includes an opinionated set of workflows and deployment scripts. These templates are meant to be a one-time starting point for new projects and customized as needed. Improvements made over time must be manually applied to existing projects. These are examples, **not** frameworks.

### Build Tools CI Dockerfile

Pantheon maintains a [Build Tools CI Dockerfile](https://github.com/pantheon-systems/docker-build-tools-ci/), which is deployed to [`quay.io`](https://quay.io/repository/pantheon-public/build-tools-ci?tab=tags), for use in Continuous Integration environments. It contains common Pantheon tools, such as Terminus and the Terminus Build Tools plugin. The deployed image tags follow semantic versioning.

### Quicksilver Pushback

[Quicksilver pushback](https://github.com/pantheon-systems/quicksilver-pushback/) is a project that makes use of Pantheon's [Quicksilver Webhooks](https://pantheon.io/docs/quicksilver) to apply code commits made on Pantheon to an external Git provider.
