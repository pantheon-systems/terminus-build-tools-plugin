#!/bin/bash

set -e

SOURCE_COMPOSER_PROJECT="$1"
CI_PROVIDER="$2"
EXTRA_ARGS="$3"

BUILD_TOOLS_VERSION="dev-master"
if [[ -n "$CIRCLE_BRANCH" ]]; then
    BUILD_TOOLS_VERSION="dev-${CIRCLE_BRANCH}#${CIRCLE_SHA1}"
fi

TERMINUS_SITE=build-tools-$CIRCLE_BUILD_NUM
TARGET_REPO=$GITLAB_USER/$TERMINUS_SITE
CLONE_URL="https://oauth2:${GITLAB_TOKEN}@gitlab.com/${TARGET_REPO}.git"

# Clear Composer cache before running build:project:create
composer clear-cache

# Build a test project on gitlab
terminus build:project:create -n "$SOURCE_COMPOSER_PROJECT" "$TERMINUS_SITE" --git=gitlab --ci=$CI_PROVIDER --team="$TERMINUS_ORG" --email="$GIT_EMAIL" --env="BUILD_TOOLS_VERSION=$BUILD_TOOLS_VERSION" $EXTRA_ARGS
# Confirm that the Pantheon site was created
terminus site:info "$TERMINUS_SITE"
# Confirm that the GitLab project was created
git clone "$CLONE_URL" "$TARGET_REPO_WORKING_COPY"
