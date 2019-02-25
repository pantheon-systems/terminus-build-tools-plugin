# TODO: Watch the GitLab CI build. Exit with an error if that job fails.

curl -s https://raw.githubusercontent.com/zaquestion/lab/master/install.sh | bash

# Confirm that GitLab was configured for testing, and that the first test passed.
set +ex

cd "$TARGET_REPO_WORKING_COPY"
export LAB_CORE_HOST
export LAB_CORE_USER
export LAB_CORE_TOKEN
(
  echo 'export LAB_CORE_HOST=https://gitlab.com'
  echo 'export LAB_CORE_USER=$GITLAB_USER'
  echo 'export LAB_CORE_TOKEN=$GITLAB_TOKEN'
) >> $BASH_ENV
source $BASH_ENV

# Comment out ci waiting as it seems unstable. https://github.com/zaquestion/lab/issues/240
# lab ci status --wait

