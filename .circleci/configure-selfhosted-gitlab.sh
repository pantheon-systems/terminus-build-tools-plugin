#!/bin/bash

#=====================================================================================================================
# Configure self-hosted GitLab and get a personal access token.
#
#=====================================================================================================================

SIGNIN_URL=$(curl -Ls -o /dev/null -w %{url_effective} -k https://gitlab-secure/)

RESET_TOKEN=$(sed "s/https:\/\/gitlab\-secure\/users\/password\/edit?reset_password_token=//g" <<< $SIGNIN_URL)

echo $RESET_TOKEN
