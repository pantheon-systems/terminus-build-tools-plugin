#!/bin/bash

set -e

TERMINUS_SITE=build-tools-$CIRCLE_BUILD_NUM

# Repair the test project on github
terminus build:project:repair -n "$TERMINUS_SITE" --email="$GIT_EMAIL"

cd "$TARGET_REPO_WORKING_COPY"

# Remove the fixture cleanup script so that the pull requests et. al.
# created in the test repo are not deleted. We'll remove them in this
# process so that we can better test the results of the operations.
FIXTURE_CLEANUP_SCRIPT="$TARGET_REPO_WORKING_COPY/.ci/deploy/pantheon/dev-multidev"

# This presumes the test script layout of the example-drops-8-composer repo
sed -e '/build:env:delete:pr/ s/^#*/#/' -i $FIXTURE_CLEANUP_SCRIPT
chmod +x "$FIXTURE_CLEANUP_SCRIPT"
git add "$FIXTURE_CLEANUP_SCRIPT"
git commit -m "build:env:delete:pr commented out for testing"

# Create a test pull request
function createTestPR()
{
    TEST_BRANCH_NAME="$1"
    TEST_COMMENT="$2"

    # Make a branch so for our test commit
    git checkout -b "$TEST_BRANCH_NAME" master

    # Add a comment to the README so that we know what this was made for
    echo "$TEST_COMMENT" >> "$TARGET_REPO_WORKING_COPY/README.md"
    git add README.md
    git commit -m "$TEST_COMMENT"

    # Push the branch
    ORIGIN="https://$GITHUB_TOKEN:x-oauth-basic@github.com/$GITHUB_USER/$TERMINUS_SITE.git"
    git push $ORIGIN "$TEST_BRANCH_NAME" 2>&1 | sed -e "s/$GITHUB_TOKEN/[REDACTED]/g"

    # Create the pull request
    hub pull-request -m "$TEST_COMMENT" -b master -h "$TEST_BRANCH_NAME" 2>&1 | sed -e "s/$GITHUB_TOKEN/[REDACTED]/g"

    # Back to master
    git checkout master
}

# Make a pull request to actually run tests
createTestPR 'test-after-repair' "Test after repair"

# Create a bunch of pull requests that will not run any tests.
# We do this so that we'll have to make more than one API request
# to find our test PR.
for n in $(seq 1 10) ; do
    createTestPR "no-op-$n" "[ci skip] Pull request that is not tested (#$n)"
done

