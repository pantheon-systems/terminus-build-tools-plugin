#!/bin/bash

# Note that there is a race condition here. The environment `pr-1` is going
# to be created by the other test process that is running asynchronously.
# Once it shows up, we want to merge the PR and run `build:env:merge`
# ourselves. If we are successful in doing this quickly enough, then our
# test will pass, but we will break the async test.

# This capability is removed until we can figure out a deterministic way to do it.

# Do -not- fail on errors (yet)
set +e

TERMINUS_SITE=build-tools-$CIRCLE_BUILD_NUM

# We could go to some work to recover the environment name for
# the branch 'test-after-repair'. Instead, though, we will assume
# that since our test creates a brand-new-repo, and the test-github-repair
# script always the 'test-after-repair' PR first, then the pull request
# we are expecting should always be "pr-1".
TERMINUS_ENV=pr-1

# Wait for our environment to show up, because Waiting / watching
# the Circle workflow is not reliable. If the 'wait for Circle' step
# worked perfectly, then this loop should pass through without sleeping.
STATUS=1
COUNT=0
while [ $STATUS -ne 0 ] ; do
    terminus env:info "$TERMINUS_SITE.$TERMINUS_ENV"
    STATUS="$?"
    if [ $STATUS -ne 0 ] ; then
        COUNT=$(($COUNT+1))
        if [ $COUNT -ge 20 ] ; then
            echo "Timed out waiting for $TERMINUS_SITE.$TERMINUS_ENV"
            exit 1
        fi
        echo "Waiting half a minute for $TERMINUS_SITE.$TERMINUS_ENV"
        sleep 30
    fi
done

# Env is now returned but not ready yet.
terminus build:workflow:wait "$TERMINUS_SITE.$TERMINUS_ENV" "Create a Multidev environment" --max=300

# Fail on errors
set -e

# We expect that `build:env:delete:pr` should not delete any environments
terminus -n build:env:delete:pr "$TERMINUS_SITE" --yes

# Confirm that our test environment still exists.
terminus env:info "$TERMINUS_SITE.$TERMINUS_ENV"

# Merge the PR branch into master and push it
cd "$TARGET_REPO_WORKING_COPY"
git checkout master
git merge -m 'Merge to master' test-after-repair

# Push the branch
ORIGIN="https://$GITHUB_TOKEN:x-oauth-basic@github.com/$GITHUB_USER/$TERMINUS_SITE.git"
git push $ORIGIN master | sed -e "s/$GITHUB_TOKEN/[REDACTED]/g"

echo "About to run build:env:merge..."

# Run `build:env:merge` to see if it works.
terminus -n build:env:merge "$TERMINUS_SITE.$TERMINUS_ENV" --yes

# Since we merged our PR branch above, this should cause our pull
# request to be marked as merged, which will make our environment
# $TERMINUS_ENV eligible for deletion. We therefore expect build:env:delete:pr
# to delete it.
echo "About to run build:env:delete:pr..."
TERMINUS_BUILD_TOOLS_REPO_PROVIDER_PER_PAGE=10 terminus -n build:env:delete:pr "$TERMINUS_SITE" --yes

# Do -not- fail on errors any more
set +e

echo "Wait 30 seconds to be sure the environment gets deleted"
sleep 30

# Confirm that our test environment no longer exists.
echo "Check to see if $TERMINUS_SITE.$TERMINUS_ENV still exists."
terminus env:info "$TERMINUS_SITE.$TERMINUS_ENV"
STATUS="$?"

# Assert that status is non-zero
if [ $STATUS -eq 0 ] ; then
    echo "Environment $TERMINUS_SITE.$TERMINUS_ENV should have been deleted, but was not."
    exit 1
fi
echo "Environment $TERMINUS_SITE.$TERMINUS_ENV deleted as expected."
