#!/bin/bash

set -e

cd "$TARGET_REPO_WORKING_COPY"

echo -e "\nMerging branch $TEST_BRANCH_NAME into master"

git checkout master

git merge -m 'Merge to master' $TEST_BRANCH_NAME

git push origin master

# Wait 30 seconds for the merge to register and the CI pipeline to start
sleep 30