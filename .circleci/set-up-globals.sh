#!/bin/bash

set -ex

# Commands below this line would not be transferable to a docker container
cd ~/terminus_build_tools_plugin
mkdir -p $HOME/.terminus/plugins
ln -s $(pwd) $HOME/.terminus/plugins
terminus list -n build

set +ex
terminus auth:login -n --machine-token="$TERMINUS_TOKEN"
touch $HOME/.ssh/config
echo "StrictHostKeyChecking no" >> "$HOME/.ssh/config"
git config --global user.email "$GIT_EMAIL"
git config --global user.name "Circle CI"
# Ignore file permissions.
git config --global core.fileMode false
