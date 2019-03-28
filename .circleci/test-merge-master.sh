#!/bin/bash

set -e

TERMINUS_SITE=build-tools-$CIRCLE_BUILD_NUM
TERMINUS_ENV=test-after-repair

# Merge the PR branch into master and push it
cd "$TARGET_REPO_WORKING_COPY"
git checkout master
git merge -m 'Merge to master' test-after-repair

# Push the branch
ORIGIN="https://$GITHUB_TOKEN:x-oauth-basic@github.com/$GITHUB_USER/$TERMINUS_SITE.git"
git push $ORIGIN master | sed -e "s/$GITHUB_TOKEN/[REDACTED]/g"

# Merge the multidev for the PR into the dev environment
terminus -n build:env:merge "$TERMINUS_SITE.$TERMINUS_ENV" --yes

# Run updatedb on the dev environment
terminus -n drush $TERMINUS_SITE.dev -- updatedb --yes

# If there are any exported configuration files, then import them
if [ -f "config/system.site.yml" ] ; then
  terminus -n drush "$TERMINUS_SITE.dev" -- config-import --yes
fi

# Delete old multidev environments associated with a PR that has been
# merged or closed.
terminus -n build:env:delete:pr "$TERMINUS_SITE" --yes
