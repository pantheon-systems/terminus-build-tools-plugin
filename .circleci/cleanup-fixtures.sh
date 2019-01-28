#!/bin/bash

set -e

TERMINUS_SITE=build-tools-$CIRCLE_PROJECT_USERNAME-$CIRCLE_BUILD_NUM

# Delete our github repository and Pantheon site
terminus build:env:obliterate -n --yes "$TERMINUS_SITE"

# Delete any ssh key we may have created for this test
for key in $(terminus ssh-key:list --fields=Description,ID 2>/dev/null | grep "ci-bot-build-tools-$CIRCLE_BUILD_NUM" | sed -e 's/ *[^ ]* *//') ; do echo "Remove $key"; terminus ssh-key:remove $key ; done
