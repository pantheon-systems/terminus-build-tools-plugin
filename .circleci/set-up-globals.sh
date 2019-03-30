#!/bin/bash

#=====================================================================================================================
# EXPORT needed environment variables
#
# Circle CI 2.0 does not yet expand environment variables so they have to be manually EXPORTed
# Once environment variables can be expanded this section can be removed
# See: https://discuss.circleci.com/t/unclear-how-to-work-with-user-variables-circleci-provided-env-variables/12810/11
# See: https://discuss.circleci.com/t/environment-variable-expansion-in-working-directory/11322
# See: https://discuss.circleci.com/t/circle-2-0-global-environment-variables/8681
#=====================================================================================================================
(
  echo 'export PATH=$PATH:$HOME/bin'
  echo 'export TARGET_REPO_WORKING_COPY=$HOME/system-under-test'
) >> $BASH_ENV
source $BASH_ENV

set -ex

cd ~/terminus_build_tools_plugin
mkdir -p $HOME/.terminus/plugins
ln -s $(pwd) $HOME/.terminus/plugins
terminus list -n build
terminus --version

set +ex
terminus auth:login -n --machine-token="$TERMINUS_TOKEN"
touch $HOME/.ssh/config
git config --global user.email "$GIT_EMAIL"
git config --global user.name "Circle CI"
# Ignore file permissions.
git config --global core.fileMode false
