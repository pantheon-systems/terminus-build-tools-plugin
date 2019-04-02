#!/bin/bash

set -e

TERMINUS_SITE=build-tools-$CIRCLE_BUILD_NUM

terminus build:secrets:set $TERMINUS_SITE key val --file=build-testing.json
terminus build:secrets:set $TERMINUS_SITE author rvtraveller --file=build-testing.json

# Confirm that we can retrieve a value
singlevalue=$(terminus build:secrets:show $TERMINUS_SITE author --file=build-testing.json)

test singlevalue = "rvtraveller"

terminus build:secrets:delete $TERMINUS_SITE key --file=build-testing.json
terminus build:secrets:delete $TERMINUS_SITE author --file=build-testing.json

emptyvalues=$(terminus build:secrets:list $TERMINUS_SITE --file=build-testing.json)

test emptyvalues = ""

