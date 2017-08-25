#!/bin/bash

set -e

TERMINUS_SITE=build-tools-$CIRCLE_BUILD_NUM

terminus build:env:obliterate -n --yes "$TERMINUS_SITE"
