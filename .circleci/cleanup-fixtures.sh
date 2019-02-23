#!/bin/bash

set -e

TERMINUS_SITE=build-tools-$CIRCLE_BUILD_NUM

# Delete our github repository and Pantheon site
# TODO: Maybe allow user to set an environment variable to control this?
# terminus build:env:obliterate -n --yes "$TERMINUS_SITE"

# Delete any ssh key we may have created for this test
for key in $(terminus ssh-key:list --format=csv --fields=ID,Description 2>/dev/null | grep ci-bot-build-tools | sed -e 's/ci-bot-build-tools-//' | sort -rg | sed -e '1,12d' | sed -e 's/,.*//') ; do echo "Remove $key"; terminus ssh-key:remove $key ; done
