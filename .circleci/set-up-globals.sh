#!/bin/bash

set -ex

SCRIPT_DIR="`dirname -- "$0"`"

$SCRIPT_DIR/ratelimit_check

# The section would be transferable to a DOCKERFILE
apt-get update

$SCRIPT_DIR/ratelimit_check

apt-get install -y ruby

$SCRIPT_DIR/ratelimit_check

gem install circle-cli

$SCRIPT_DIR/ratelimit_check

composer global require -n "hirak/prestissimo:^0.3"

$SCRIPT_DIR/ratelimit_check

git clone https://github.com/pantheon-systems/terminus.git ~/terminus

$SCRIPT_DIR/ratelimit_check

cd ~/terminus && composer install

$SCRIPT_DIR/ratelimit_check

ln -s ~/terminus/bin/terminus /usr/local/bin/terminus

# Commands below this line would not be transferable to a docker container
cd ~/terminus_build_tools_plugin
mkdir -p $HOME/.terminus/plugins
ln -s $(pwd) $HOME/.terminus/plugins
terminus list -n build

$SCRIPT_DIR/ratelimit_check

set +ex
terminus auth:login -n --machine-token="$TERMINUS_TOKEN"

$SCRIPT_DIR/ratelimit_check

touch $HOME/.ssh/config
echo "StrictHostKeyChecking no" >> "$HOME/.ssh/config"
git config --global user.email "$GIT_EMAIL"
git config --global user.name "Circle CI"
# Ignore file permissions.
git config --global core.fileMode false
