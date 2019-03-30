#!/bin/bash

set -e

TERMINUS_SITE=build-tools-$CIRCLE_BUILD_NUM

# Repair the test project on github
terminus build:project:repair -n "$TERMINUS_SITE" --email="$GIT_EMAIL"

# Make a branch so that we can test a PR
cd "$TARGET_REPO_WORKING_COPY"
git checkout -b test-after-repair
echo 'Test after repair' >> README.md
git add README.md
git commit -m "Test after repair"

# Push the branch
ORIGIN="https://$GITHUB_TOKEN:x-oauth-basic@github.com/$GITHUB_USER/$TERMINUS_SITE.git"
git push $ORIGIN test-after-repair | sed -e "s/$GITHUB_TOKEN/[REDACTED]/g"

# Create the pull request
hub pull-request -m "Test after repair" -b master -h test-after-repair


