#!/bin/bash

# Wait a bit for the test to start. This does not make the entire build
# take longer, it just means we miss out on watching the first bit of the
# test. If we do not sleep for long enough here, then the test might not
# have started running by the time we get to 'circle watch', in which case
# an error will be thrown and we won't watch any of the build at all.
sleep 30

# Confirm that Circle was configured for testing, and that the first test passed.
set +ex

cd "$TARGET_REPO_WORKING_COPY"
circle token "$CIRCLE_TOKEN"
circle watch || echo 'Skipping the watch'

