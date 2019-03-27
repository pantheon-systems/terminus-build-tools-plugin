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
git push origin test-after-repair
hub pull-request -m "Test after repair" -b master -h test-after-repair


