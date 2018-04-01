# Terminus Build Tools Plugin Development

[![CircleCI](https://circleci.com/gh/pantheon-systems/terminus-build-tools-plugin.svg?style=shield)](https://circleci.com/gh/pantheon-systems/terminus-build-tools-plugin)
[![Terminus v1.x Compatible](https://img.shields.io/badge/terminus-v1.x-green.svg)](https://github.com/pantheon-systems/terminus-build-tools-plugin/tree/1.x)

Terminus Build Tools Plugin development is done through a fork/PR workflow for
any outside contributors. There is an automatic test suite which must pass
before pull requests are merged. However, the test suite does require some setup
for each fork to work. Due to privacy/security considerations, each fork must
use its own circle-ci instance as it requires access to GitHub, Pantheon etc.

## Setup

- Fork this repository (Github account required)
- Login/register with [CircleCI](https://circleci.com/).
- In CircleCI UI:
   - Create a CI instance for your fork.
   - Add environment variables. By default, this would be at: 
   https://circleci.com/gh/YOURUSERNAME/terminus-build-tools-plugin/edit#env-vars
     - `BITBUCKET_PASS` Bitbucket app password.
     - `BITBUCKET_USER` Your Bitbucket username.
     - `CIRCLE_TOKEN` A generated CircleCI token.
     - `GITHUB_TOKEN` A generated GitHub token - needs at least create/edit/delete repo permissions
     - `GITHUB_USER` Your GitHub username.
     - `GIT_EMAIL` The email to use for commits.
     - `TERMINUS_ORG` The agency account to use for creating Pantheon sites.
     - `TERMINUS_TOKEN` A generated Pantheon/Terminus access token.
