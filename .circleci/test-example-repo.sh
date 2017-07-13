#!/bin/bash

set -ex

TERMINUS_SITE=build-tools-$CIRCLE_BUILD_NUM

BRANCH=$(echo $CIRCLE_BRANCH | grep -v '^\(master\|[0-9]\+.x\)$')
PR_BRANCH=${BRANCH:+dev-$BRANCH}
SOURCE_COMPOSER_PROJECT="$1"
TARGET_REPO=$GITHUB_USERNAME/$TERMINUS_SITE
TARGET_REPO_WORKING_COPY=$HOME/$TERMINUS_SITE
BUILD_TOOLS_VERSION=${PR_BRANCH:-$CIRCLE_BRANCH}


terminus build:project:create -n "$SOURCE_COMPOSER_PROJECT" "$TERMINUS_SITE" --team="$TERMINUS_ORG" --email="$GIT_EMAIL" --env="BUILD_TOOLS_VERSION=$BUILD_TOOLS_VERSION"
# Confirm that the Pantheon site was created
terminus site:info "$TERMINUS_SITE"
# Confirm that the Github project was created
git clone "https://github.com/${TARGET_REPO}.git" "$TARGET_REPO_WORKING_COPY"
# Confirm that Circle was configured for testing, and that the first test passed.

set +ex
cd "$TARGET_REPO_WORKING_COPY" && circle token "$CIRCLE_TOKEN" && circle watch
