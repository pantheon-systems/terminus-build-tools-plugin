#!/bin/bash

set -e

SOURCE_COMPOSER_PROJECT="$1"
EXTRA_ARGS="$2"

BUILD_TOOLS_VERSION="dev-master"
if [[ -n "$CIRCLE_BRANCH" ]]; then
    BUILD_TOOLS_VERSION="dev-${CIRCLE_BRANCH}#${CIRCLE_SHA1}"
fi

TERMINUS_SITE=build-tools-$CIRCLE_BUILD_NUM
TARGET_REPO=$GITHUB_USER/$TERMINUS_SITE
CLONE_URL="https://github.com/${TARGET_REPO}.git"

# Clear Composer cache before running build:project:create
composer clear-cache

# Build a test project on github
terminus build:project:create -n "$SOURCE_COMPOSER_PROJECT" "$TERMINUS_SITE" --team="$TERMINUS_ORG" --email="$GIT_EMAIL" --env="BUILD_TOOLS_VERSION=$BUILD_TOOLS_VERSION" $EXTRA_ARGS
echo "Project created"
# Confirm that the Pantheon site was created
terminus site:info "$TERMINUS_SITE"
# Confirm that the Github project was created
git clone "$CLONE_URL" "$TARGET_REPO_WORKING_COPY"

# Replace with right docker image to be able to test current version of build tools as 6.x uses Terminus 2 and we need Terminus 3.
# This change should be rolled back once we are able to have 8.x in the template (which is not possible right now for example-wordpress-composer as it breaks the tests).
cd $TARGET_REPO_WORKING_COPY
sed -i 's/quay.io\/pantheon\-public\/build\-tools\-ci:6.x/pantheonpublic\/build\-tools\-ci:8.x\-php7.4/g' ./.circleci/config.yml
git add .circleci/config.yml
git commit --allow-empty -m "Update Docker image if needed."
git push origin master
