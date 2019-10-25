#!/bin/bash

# Wait a bit for the test to start. This does not make the entire build
# take longer, it just means we miss out on watching the first bit of the
# test. If we do not sleep for long enough here, then the test might not
# have started running by the time we start watching, in which case
# an error will be thrown and we won't watch any of the build at all.
sleep 30

# Confirm that CI was configured for testing, and that the first test passed.
set -e

cd "$TARGET_REPO_WORKING_COPY"

BRANCH_TO_WATCH=${1:-master}

echo -e "\nWatching the latest CI pipeline on the branch $BRANCH_TO_WATCH for the project $TERMINUS_SITE"

terminus -n build:ci:watch "$TERMINUS_SITE" --branch-name="$BRANCH_TO_WATCH"