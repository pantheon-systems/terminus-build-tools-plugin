#!/bin/bash

set -e

SOURCE_COMPOSER_PROJECT="$1"
EXTRA_ARGS="$2"

#BUILD_TOOLS_VERSION="dev-master"
#if [[ -n "$CIRCLE_BRANCH" ]]; then
#    BUILD_TOOLS_VERSION="dev-${CIRCLE_BRANCH}"
#fi
unset BUILD_TOOLS_VERSION

CLONE_URL="git@github.com:$GITLAB_USER/$TERMINUS_SITE.git"

# Clear Composer cache before running build:project:create
composer clear-cache

# Build a test project on gitlab
terminus build:project:create -n "$SOURCE_COMPOSER_PROJECT" "$TERMINUS_SITE" --git=gitlab --team="$TERMINUS_ORG" --email="$GIT_EMAIL" --env="BUILD_TOOLS_VERSION=$BUILD_TOOLS_VERSION" $EXTRA_ARGS
# Confirm that the Pantheon site was created
terminus site:info "$TERMINUS_SITE"
# Confirm that the GitLab project was created
git clone "$CLONE_URL" "$TARGET_REPO_WORKING_COPY"
