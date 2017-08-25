#!/bin/bash

# This was `set -ex`, but removed echo to avoid leaking $BITBUCKET_PASS
# TODO: We should also pass the $GITHUB_TOKEN when cloning the GitHub repo so that it can be a private repo if desired.
set -e

SELF_DIRNAME="`dirname -- "$0"`"
SCRIPT_DIR="`cd -P -- "$SELF_DIRNAME" && pwd -P`"

TERMINUS_SITE=build-tools-$CIRCLE_BUILD_NUM

# If we are on the master branch
if [[ $CIRCLE_BRANCH == "master" ]]
then
    PR_BRANCH="dev-master"
else
    # If this is a pull request use the PR number
    if [[ -z "$CIRCLE_PULL_REQUEST" ]]
    then
        # Stash PR number
        PR_NUMBER=${CIRCLE_PULL_REQUEST##*/}

        # Multidev name is the pull request number
        PR_BRANCH="pr-$PR_NUMBER"
    else
        # Otherwise use the build number
        PR_BRANCH="dev-$CIRCLE_BUILD_NUM"
    fi
fi

SOURCE_COMPOSER_PROJECT="$1"
TARGET_REPO_WORKING_COPY=$HOME/$TERMINUS_SITE
GIT_PROVIDER="$2"


# If we are on the 1.x branch set the build tools version to 1.x
if [[ $CIRCLE_BRANCH == "1.x" ]]
then
    BUILD_TOOLS_VERSION="${CIRCLE_BRANCH}#${CIRCLE_SHA1}"
# Otherwise use the current branch
else
    BUILD_TOOLS_VERSION="dev-${CIRCLE_BRANCH}#${CIRCLE_SHA1}"
fi

if [ "$GIT_PROVIDER" == "github" ]; then
    TARGET_REPO=$GITHUB_USERNAME/$TERMINUS_SITE
    CLONE_URL="https://github.com/${TARGET_REPO}.git"
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

$SCRIPT_DIR/ratelimit_check

terminus build:project:create -n "$SOURCE_COMPOSER_PROJECT" "$TERMINUS_SITE" --git=$GIT_PROVIDER --team="$TERMINUS_ORG" --email="$GIT_EMAIL" --env="BUILD_TOOLS_VERSION=$BUILD_TOOLS_VERSION"

$SCRIPT_DIR/ratelimit_check

# Confirm that the Pantheon site was created
terminus site:info "$TERMINUS_SITE"

$SCRIPT_DIR/ratelimit_check

# Confirm that the Github project was created
git clone "$CLONE_URL" "$TARGET_REPO_WORKING_COPY"

$SCRIPT_DIR/ratelimit_check

# Confirm that Circle was configured for testing, and that the first test passed.

(
    set +ex
    cd "$TARGET_REPO_WORKING_COPY" && circle token "$CIRCLE_TOKEN" && circle watch
)

$SCRIPT_DIR/ratelimit_check

# Delete our test site, etc.
./.circleci/cleanup-fixtures.sh

$SCRIPT_DIR/ratelimit_check
