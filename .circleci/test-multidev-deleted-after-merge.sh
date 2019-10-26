#!/bin/bash

set -e

cd "$TARGET_REPO_WORKING_COPY"

# Confirm that our test environment no longer exists.
echo "Check to see if $TERMINUS_SITE.$TEST_MULTIDEV_ENV still exists."
terminus env:info "$TERMINUS_SITE.$TEST_MULTIDEV_ENV"
STATUS="$?"

# Assert that status is non-zero
if [ $STATUS -eq 0 ] ; then
    echo "Environment $TERMINUS_SITE.$TEST_MULTIDEV_ENV should have been deleted, but was not."
    exit 1
fi
echo "Environment $TERMINUS_SITE.$TEST_MULTIDEV_ENV deleted as expected."
