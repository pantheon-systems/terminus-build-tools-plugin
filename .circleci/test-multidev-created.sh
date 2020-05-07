#!/bin/bash

set -e

cd "$TARGET_REPO_WORKING_COPY"

# Wait for our environment to show up, because Waiting / watching
# the Circle workflow is not reliable. If the 'wait for Circle' step
# worked perfectly, then this loop should pass through without sleeping.
STATUS=1
COUNT=0
while [ $STATUS -ne 0 ] ; do
    terminus env:info "$TERMINUS_SITE.$TEST_MULTIDEV_ENV"
    STATUS="$?"
    if [ $STATUS -ne 0 ] ; then
        COUNT=$(($COUNT+1))
        if [ $COUNT -ge 20 ] ; then
            echo "Timed out waiting for $TERMINUS_SITE.$TEST_MULTIDEV_ENV"
            exit 1
        fi
        echo "Waiting half a minute for $TERMINUS_SITE.$TEST_MULTIDEV_ENV"
        sleep 30
    fi
done