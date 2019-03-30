#!/bin/bash

set -e

TERMINUS_SITE=build-tools-$CIRCLE_BUILD_NUM

# Repair the test project on github
terminus build:project:repair -n "$TERMINUS_SITE" --email="$GIT_EMAIL"

# Remove the fixture cleanup script so that the pull requests et. al.
# created in the test repo are not deleted. We'll remove them in this
# process so that we can better test the results of the operations.
FIXTURE_CLEANUP_SCRIPT="$TARGET_REPO_WORKING_COPY/.ci/scripts/05-merge-master"

# This presumes the test script layout of the example-drops-8-composer repo
echo << __EOT__ > $FIXTURE_CLEANUP_SCRIPT
#!/bin/bash

echo "Script removed for testing"
__EOT__
chmod +x "$FIXTURE_CLEANUP_SCRIPT"
git -C "$TARGET_REPO_WORKING_COPY" add "$FIXTURE_CLEANUP_SCRIPT"
git -C "$TARGET_REPO_WORKING_COPY" commit -m "Script removed for testing"

# Create a test pull request
function createTestPR()
{
    TEST_BRANCH_NAME="$1"
    TEST_COMMENT="$2"

    # Make a branch so for our test commit
    git -C "$TARGET_REPO_WORKING_COPY" checkout -b "$TEST_BRANCH_NAME" master

    # Add a comment to the README so that we know what this was made for
    echo "$TEST_COMMENT" >> "$TARGET_REPO_WORKING_COPY/README.md"
    git -C "$TARGET_REPO_WORKING_COPY" add README.md
    git -C "$TARGET_REPO_WORKING_COPY" commit -m "$TEST_COMMENT"

    # Push the branch
    ORIGIN="https://$GITHUB_TOKEN:x-oauth-basic@github.com/$GITHUB_USER/$TERMINUS_SITE.git"
    git push $ORIGIN test-after-repair | sed -e "s/$GITHUB_TOKEN/[REDACTED]/g"

    # Create the pull request
    hub -C "$TARGET_REPO_WORKING_COPY" pull-request "$TEST_COMMENT" -b master -h "$TEST_BRANCH_NAME"

    # Back to master
    git -C "$TARGET_REPO_WORKING_COPY" checkout master
}

# Create a bunch of pull requests that will not run any tests.
# We do this so that we'll have to make more than one API request
# to find our test PR.
for n in $(seq 1 59) ; do
    createTestPR "no-op-$n" "[ci skip] Pull request that is not tested (#$n)"
done

# Make a pull request to actually run tests
createTestPR 'test-after-repair' "Test after repair"
