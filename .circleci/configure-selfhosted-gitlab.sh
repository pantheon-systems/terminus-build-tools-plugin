#!/bin/bash

#=====================================================================================================================
# Configure self-hosted GitLab and get a personal access token.
#
#=====================================================================================================================

# For safety, just sleep for 30 seconds to try and make sure GitLab is good.
sleep 30

SIGNIN_URL=$(curl -Ls -o /dev/null -w %{url_effective} -k https://gitlab-secure/)

RESET_TOKEN=$(sed "s/https:\/\/gitlab\-secure\/users\/password\/edit?reset_password_token=//g" <<< $SIGNIN_URL)

echo "Reset token:"
echo $RESET_TOKEN
