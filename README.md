# Terminus Build Tools Plugin

[![Terminus v1.x Compatible](https://img.shields.io/badge/terminus-v1.x-green.svg)](https://github.com/pantheon-systems/terminus-build-tools-plugin/tree/1.x)

Terminus Plugin that contain a collection of commands useful during the build step on a [Pantheon](https://www.pantheon.io) site that uses a GitHub PR workflow.

See below for the list of supported commands. This plugin is only available for Terminus 1.x.

## Configuration

In order to use this plugin, you will need to set up a GitHub repository and a CircleCI project for the site you wish to build. Credentials will also need to be set up (to be documented).

## Examples

### Delete Old Multidevs

`terminus build-env:delete my-pantheon-site '^ci-' --keep=2 --delete-branch`

### List Old Multidevs

`terminus build-env:list`

## Future

- Create Pantheon Multidev
- Merge Pantheon Multidev
- Post comment to GitHub or GitLab

## Installation
For help installing, see [Terminus's Wiki](https://github.com/pantheon-systems/terminus/wiki/Plugins)
```
mkdir -p ~/terminus/plugins
composer create-project -d ~/terminus/plugins pantheon-systems/terminus-build-tools-plugin:~1
```

## Help
Run `terminus list build-env` for a complete list of available commands. Use `terminus help <command>` to get help on one command.
