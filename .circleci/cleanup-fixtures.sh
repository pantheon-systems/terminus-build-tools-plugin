#!/bin/bash

set -e

TERMINUS_SITE=build-tools-$CIRCLE_BUILD_NUM

# If we didn't get far enough to install Terminus, then exit with
# no further action.
if [ -z "$(which terminus)" ] ; then
  exit 0
fi

terminus build:env:obliterate -n --yes "$TERMINUS_SITE"
