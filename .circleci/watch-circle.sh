#!/bin/bash

# Confirm that Circle was configured for testing, and that the first test passed.
set +ex

cd "$TARGET_REPO_WORKING_COPY"
circle token "$CIRCLE_TOKEN"
circle watch
