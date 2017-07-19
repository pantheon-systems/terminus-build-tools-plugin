#!/bin/bash

# This was `set -ex`, but removed echo to avoid leaking $BITBUCKET_PASS
# TODO: We should also pass the $GITHUB_TOKEN when cloning the GitHub repo so that it can be a private repo if desired.
set -e

TERMINUS_SITE=build-tools-$CIRCLE_BUILD_NUM

BRANCH=$(echo $CIRCLE_BRANCH | grep -v '^\(master\|[0-9]\+.x\)$')
PR_BRANCH=${BRANCH:+dev-$BRANCH}
SOURCE_COMPOSER_PROJECT="$1"
TARGET_REPO_WORKING_COPY=$HOME/$TERMINUS_SITE
BUILD_TOOLS_VERSION=${PR_BRANCH:-$CIRCLE_BRANCH}
GIT_PROVIDER="$2"

if [ "$GIT_PROVIDER" == "github" ]; then
    TARGET_REPO=$GITHUB_USERNAME/$TERMINUS_SITE
    CLONE_URL=  "https://github.com/${TARGET_REPO}.git"
else
    if [ "$GIT_PROVIDER" == "bitbucket" ]; then
        TARGET_REPO=$BITBUCKET_USERNAME/$TERMINUS_SITE
        # Bitbucket repo is private, thus HTTP basic auth is integrated into clone URL
        CLONE_URL="https://$BITBUCKET_USER:$BITBUCKET_PASS@bitbucket.org/${TARGET_REPO}.git"
    else
        echo "Unsupported GIT_PROVIDER. Valid values are: github, bitbucket"
        exit 1
    fi
fi

terminus build:project:create -n "$SOURCE_COMPOSER_PROJECT" "$TERMINUS_SITE" --git=$GIT_PROVIDER --team="$TERMINUS_ORG" --email="$GIT_EMAIL" --env="BUILD_TOOLS_VERSION=$BUILD_TOOLS_VERSION"
# Confirm that the Pantheon site was created
terminus site:info "$TERMINUS_SITE"
# Confirm that the Github project was created
git clone "$CLONE_URL" "$TARGET_REPO_WORKING_COPY"
# Confirm that Circle was configured for testing, and that the first test passed.

set +ex
cd "$TARGET_REPO_WORKING_COPY" && circle token "$CIRCLE_TOKEN" && circle watch
