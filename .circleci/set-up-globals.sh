#!/bin/bash

set -ex

# The section would be transferable to a DOCKERFILE
apt-get update
apt-get install -y ruby
gem install circle-cli
composer global require -n "hirak/prestissimo:^0.3"
git clone https://github.com/pantheon-systems/terminus.git ~/terminus
cd ~/terminus && composer install
ln -s ~/terminus/bin/terminus /usr/local/bin/terminus

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
