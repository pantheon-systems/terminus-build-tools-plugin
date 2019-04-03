#!/bin/bash

set -e

TERMINUS_SITE=build-tools-$CIRCLE_BUILD_NUM
TERMINUS_ENV=dev

terminus build:secrets:set "$TERMINUS_SITE.$TERMINUS_ENV" key val --file=build-testing.json
terminus build:secrets:set "$TERMINUS_SITE.$TERMINUS_ENV" author rvtraveller --file=build-testing.json

# Confirm that we can retrieve a value
singlevalue=$(terminus build:secrets:show "$TERMINUS_SITE.$TERMINUS_ENV" author --file=build-testing.json)
printf "%s\n" "Testing single value secret"
test $singlevalue = "rvtraveller"

terminus build:secrets:delete "$TERMINUS_SITE.$TERMINUS_ENV" key --file=build-testing.json
terminus build:secrets:delete "$TERMINUS_SITE.$TERMINUS_ENV" author --file=build-testing.json

emptyvalues=$(terminus build:secrets:list "$TERMINUS_SITE.$TERMINUS_ENV" --file=build-testing.json)
printf "%s\n" "Testing secret deletion"
test -z $emptyvalues

