# Terminus Build Tools Plugin

[![CircleCI](https://circleci.com/gh/pantheon-systems/terminus-build-tools-plugin.svg?style=shield)](https://circleci.com/gh/pantheon-systems/terminus-build-tools-plugin)
[![Terminus v1.x Compatible](https://img.shields.io/badge/terminus-v1.x-green.svg)](https://github.com/pantheon-systems/terminus-build-tools-plugin/tree/1.x)

Terminus Plugin that contains a collection of commands useful during the build step on a [Pantheon](https://www.pantheon.io) site that manages its files using Composer, and uses a Git PR workflow with Behat tests run via a CI provider. For detailed set-up instructions, see the [Terminus Build Tools Guide](https://pantheon.io/docs/guides/build-tools/).

## Requirements

- If you are using Terminus 2, you must use the Build Tools 2.x release
- If you are using Terminus 1, you must use the stable Build Tools 1.x release. Note that Terminus 1 is nearing [End of Life](https://pantheon.io/docs/terminus/updates#eol-timeline).

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

It is recommended that you use one of the provided example projects as a template when creating a new project. All of the example projects have been updated to use Terminus 2 and the unstable Build Tools 2.x release.

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

At the moment, the `build:project:create` command only supports services in the following combination: 

| Git Host  | CI Service |
| --------- | ---------- |
| GitHub    | CircleCI   |
| GitLab    | GitLabCI   |
| BitBucket | CircleCI   |

### Starting a new GitLab Project

```
$ terminus build:project:create --git=gitlab --team='My Agency Name' wp my-site
```

### Starting a new BitBucket Project

```
$ terminus build:project:create --git=bitbucket --team='My Agency Name' wp my-site
```

#### Limitations

- Automatic multidev deletion not working; test multidevs must be deleted manually
- Comments are not added to pull requests when multidevs are created

## Commands

The following commands are available as part of the Build Tools plugin.

### build:project:create

The `build:project:create` command is used to initialize projects within the Git PR workflow. Automated setup of the Pantheon website along with the corresponding Git and CI provider is included.

#### Command Options

Additional options are available to further customize the `build:project:create` command:
 
 | Option             | Description    |
 | ------------------ | -------------- |
 | --pantheon-site    | The name to use for the Pantheon site (defaults to the name of the Git site) | 
 | --team             | The Pantheon team to associate the site with |
 | --org              | The Git organization to place the repository in (defaults to authenticated user) |
 | --label            | The friendly name to use for the Pantheon site (defaults to the name of the Git site) |
 | --email            | The git user email address to use when committing build results |
 | --test-site-name   | The name to use when installing the test site |
 | --admin-password   | The password to use for the admin when installing the test site |
 | --admin-email      | The email address to use for the admin |
 | --stability        | The stability to use with composer when creating the project (defaults to dev) |
 | --keep             | The ability to keep a project repository cloned after your project is created |
 | --ci               | The CI provider to use. Defaults to "circleci" |
 | --git              | The git repository provider to use. Defaults to "github" |
 | --visibility       | The visibility of the project. Defaults to "public". Use "public" or "private" for GitHub and "public", "private", or "internal" for GitLab |
 | --region           | The region to create the site in. See [the Pantheon regions documentation](https://pantheon.io/docs/regions#create-a-new-site-in-a-specific-region-using-terminus) for details. |
 
See `terminus help build:project:create` for more information.
 
### build:project:repair
 
The `build:project:repair` command is used to repair projects that were created with the Build Tools plugin.
 
#### Command Options
 
Additional options are available to further customize the `build:project:repair` command:

 | Option           | Description      |
 | ---------------- | ---------------- |
 | --env            | The environment variables you would like to set on the CI system |
 
### build:comment:add:commit

The `build:comment:add:commit` command is used to add a comment to a commit on the Git Provider. This is useful in CI scripts for commenting as multidev environments are created or other code feedback is determined.

#### Command Options

Additional options are available to customize the `build:comment:add:commit` command:

 | Option           | Description      |
 | ---------------- | ---------------- |
 | --sha            | The SHA hash of the commit to add the comment to |
 | --message        | The message to post to the commit |
 | --site_url       | If provided, will include a "Visit Site" link at the start of the comment, linking to the provided site URL |
 
### build:credentials:clear
 
The `build:credentials:clear` command is available to clear cached credentials from Build Tools. This is useful when developing Build Tools or trying to remove credentials from a machine.

#### Command Options

There are no additional options for this command.

### build:env:create

The `build:env:create` command creates a multidev environment on Pantheon.

#### Command Options

 | Option           | Description      |
 | ---------------- | ---------------- |
 | --label          | The name of the environment in commit comments |
 | --clone-content  | Clone the content from the dev environment to the new multidev environment |
 | --db-only        | When cloning content, whether to only clone the database (by default, both the database and files are cloned |
 | --message        | The commit message to use when committing the built assets to Pantheon |
 
### build:env:delete:ci

The `build:env:delete:ci` command is used to delete multidev environments on Pantheon that match the CI pattern of builds (ci-*).

#### Command Options

 | Option           | Description      |
 | ---------------- | ---------------- |
 | --keep           | The number of environments matching the pattern to keep |
 | --dry-run        | If set, this command only determines which environments should be deleted but doesn't actually delete them |
 
### build:env:delete:pr

The `build:env:delete:pr` command is used to delete multidev environments on Pantheon that match the PR pattern of builds (pr-*) for PRs that have been closed.

#### Command Options

 | Option           | Description      |
 | ---------------- | ---------------- |
 | --dry-run        | If set, this command only determines which environments should be deleted but doesn't actually delete them |
 
### build:env:install

The `build:env:install` command is used to install the CMS in the specified site.

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

The `build:env:obliterate` command deletes a Pantheon site that was set up through the `build:project:create` workflow.

#### Command Options

There are no additional command options for this command.

### build:env:push

The `build:env:push` command pushes code to an existing Pantheon site/environment.

#### Command Options

 | Option           | Description      |
 | ---------------- | ---------------- |
 | --label          | The name of the site when referred to in commit comments. |
 | --message        | The commit message to use when committing built code to Pantheon |
 
### build:project:info
 
The `build:project:info` command displays information about a site created by the `build:project:create` command.

#### Command Options

There are no additional command options for this command.

### build:secrets:delete

The `build:secrets:delete` command deletes a secret from Pantheon. These secrets are commonly used for storing informatiion needed by future CI integration such as [Quicksilver Pushback](https://www.github.com/pantheon-systems/quicksilver-pushback).

#### Command Options

 | Option           | Description      |
 | ---------------- | ---------------- |
 | --file           | The name of the file to use for storing the secret. Defaults to tokens.json |

### build:secrets:list

The `build:secrets:list` command lists all secret from Pantheon. These secrets are commonly used for storing informatiion needed by future CI integration such as [Quicksilver Pushback](https://www.github.com/pantheon-systems/quicksilver-pushback).

#### Command Options

 | Option           | Description      |
 | ---------------- | ---------------- |
 | --file           | The name of the file to use for storing the secret. Defaults to tokens.json |
  
### build:secrets:set

The `build:secrets:set` command sets a secret in a Pantheon. These secrets are commonly used for storing informatiion needed by future CI integration such as [Quicksilver Pushback](https://www.github.com/pantheon-systems/quicksilver-pushback).

#### Command Options

 | Option           | Description      |
 | ---------------- | ---------------- |
 | --file           | The name of the file to use for storing the secret. Defaults to tokens.json |
 | --clear          | If set, will overwrite a secret with the existing name |
 | --skip-if-empty  | If set, will not write anything if the value passed to the command is empty |
 
### build:secrets:show

The `build:secrets:show` command shows a secret from Pantheon. These secrets are commonly used for storing informatiion needed by future CI integration such as [Quicksilver Pushback](https://www.github.com/pantheon-systems/quicksilver-pushback).

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


## Customization

You may easily create your own by forking one of the standard starter sites (linked above) and customizing it to suit. To use a custom starter, register your project on Packagist, and then use the projects org/name with the `build:project:create` command:
```
$ terminus build:project:create --team='My Agency Name' my-project/my-starter my-site
```
See [Starter Site Shortcuts](#starter-site-shortcuts) below for instructions on defining your own shortcuts for your starter projects.


### Configuration

Configuration values for the Terminus Build Tools Plugin may be stored in your Terminus Configuration file, located at `~/.terminus/config.yml`. This is especially useful for agencies who would liike every site created within their Pantheon team.

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

The examples below show how some of the other `build:env:` commands are used within test scripts. It is not usually necessary to run any of these commands directly; they may be of interest if you are customizing or building your own test scripts.

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

