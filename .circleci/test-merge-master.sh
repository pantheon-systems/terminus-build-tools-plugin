#!/bin/bash

# Do -not- fail on errors (yet)
set +e

TERMINUS_SITE=build-tools-$CIRCLE_BUILD_NUM

# We could go to some work to recover the environment name, but
# since this is a brand-new repo, we expect the environment should
# always be pr-1.
TERMINUS_ENV=pr-1

# Wait for our environment to show up
# (Waiting / watching the Circle workflow is not reliable)
STATUS=1
COUNT=0
while [ $STATUS -ne 0 ] ; do
    terminus env:info "$TERMINUS_SITE.$TERMINUS_ENV"
    STATUS="$?"
    if [ $STATUS -ne 0 ] ; then
        COUNT=$(($COUNT+1))
        if [ $COUNT -ge 10 ] ; then
            echo "Timed out waiting for $TERMINUS_SITE.$TERMINUS_ENV"
            exit 1
        fi
        echo "Waiting 1 minute for $TERMINUS_SITE.$TERMINUS_ENV"
        sleep 60
    fi
done

# Fail on errors
set -e

# Merge the PR branch into master and push it
cd "$TARGET_REPO_WORKING_COPY"
git checkout master
git merge -m 'Merge to master' test-after-repair

# Push the branch
ORIGIN="https://$GITHUB_TOKEN:x-oauth-basic@github.com/$GITHUB_USER/$TERMINUS_SITE.git"
git push $ORIGIN master | sed -e "s/$GITHUB_TOKEN/[REDACTED]/g"

# TODO: We cannot actually test the merge to master script
# here. Our local copy hasn't been set up for this (it was not
# used to push code to Pantheon, so it does not have the
# 'pantheon' remote), and also, the job associated with the PR
# that we just waited for above is also going to run `build:env:merge`.
# If we had a reliable way to identify and watch the job that will
# start on the 'master' branch, perhaps we can determine results that way.
# Maybe we could check for new commits on the dev env of $TERMINUS_SITE,
# and wait until they show up (per wait loop above)?
# terminus -n build:env:merge "$TERMINUS_SITE.$TERMINUS_ENV" --yes

# Since we mreged our PR branch above, this should delete pr-1.
# That will cause our test in progress to fail. If we wait above, then
# the PR that is running should delete pr-1 before we get here.
terminus -n build:env:delete:pr "$TERMINUS_SITE" --yes
