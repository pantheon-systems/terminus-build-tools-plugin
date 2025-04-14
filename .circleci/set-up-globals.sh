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

# Update terminus temporarily.
rm /usr/local/bin/terminus
mkdir ~/terminus && cd ~/terminus
TERMINUS_RELEASE=$(curl --silent "https://api.github.com/repos/pantheon-systems/terminus/releases/latest" | perl -nle'print $& while m#"tag_name": "\K[^"]*#g')
curl -L https://github.com/pantheon-systems/terminus/releases/download/$TERMINUS_RELEASE/terminus.phar --output terminus
chmod +x terminus
ln -s ~/terminus/terminus /usr/local/bin/terminus
terminus self:info

terminus self:plugin:install /root/terminus_build_tools_plugin

set +ex
terminus auth:login -n --machine-token="$TERMINUS_TOKEN"
touch $HOME/.ssh/config
git config --global user.email "$GIT_EMAIL"
git config --global user.name "Circle CI"
# Ignore file permissions.
git config --global core.fileMode false
