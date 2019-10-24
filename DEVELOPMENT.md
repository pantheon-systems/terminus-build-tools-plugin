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
     - `GITLAB_TOKEN` A generated GitLab token - needs at least api and read_user scopes.
     - `TERMINUS_BUILD_TOOLS_COMPOSER_AUTH` JSON formatted object to be used for composer authentication. See https://getcomposer.org/doc/03-cli.md#composer-auth

## Adding a new Provider

It is possible to add other providers. There is no plugin mechanism for providers; additional implementations must be added to the Terminus Build Tools plugin. Pull requests are welcome.

### Declare the Provider Class

In the [ProviderManager](https://github.com/pantheon-systems/terminus-build-tools-plugin/blob/master/src/ServiceProviders/ProviderManager.php) class, add the classname to the list in the `findProvider` method.

### Impementing a New CI Provider

Follow the example provided by the [CircleCIProvider](https://github.com/pantheon-systems/terminus-build-tools-plugin/blob/master/src/ServiceProviders/CIProviders/CircleCI/CircleCIProvider.php) class. A number of interfaces should be implemented:

- [CredentialClientInterface](https://github.com/pantheon-systems/terminus-build-tools-plugin/blob/master/src/Credentials/CredentialClientInterface.php): declare the credentials (e.g. OAuth tokens) the CredentialManager shoud look up or prompt for on behalf of your CI Provider.
- [CIProvider](https://github.com/pantheon-systems/terminus-build-tools-plugin/blob/master/src/ServiceProviders/CIProviders/CIProvider.php): set environment variables and configure the CI service to begin running tests.
- [PrivateKeyReceiver](https://github.com/pantheon-systems/terminus-build-tools-plugin/blob/master/src/Task/Ssh/PrivateKeyReciever.php): receive the private key that will be generated for your CI Provider. The corresponding public key is added to Pantheon.
- [LoggerAwareInterface](https://github.com/php-fig/log/blob/master/Psr/Log/LoggerAwareInterface.php): A logger will be injected into your class.

### Implement a New Git Repository Provider

Follow the example provided by the [GithubProvider](https://github.com/pantheon-systems/terminus-build-tools-plugin/blob/master/src/ServiceProviders/RepositoryProviders/GithubProvider.php) class. A number of interfaces should be implemented:

- [CredentialClientInterface](https://github.com/pantheon-systems/terminus-build-tools-plugin/blob/master/src/Credentials/CredentialClientInterface.php): declare the credentials (e.g. OAuth tokens) the CredentialManager shoud look up or prompt for on behalf of your CI Provider.
- [GitProvider](https://github.com/pantheon-systems/terminus-build-tools-plugin/blob/master/src/ServiceProviders/RepositoryProviders/GitProvider.php): create a repository on the remote Git service, and push a local repository to the remote service.
- [LoggerAwareInterface](https://github.com/php-fig/log/blob/master/Psr/Log/LoggerAwareInterface.php): A logger will be injected into your class.
