<?php

namespace Pantheon\TerminusBuildTools\CodeProviders;

/**
 * Build tools integration with GitHub.
 */
class GitHubProvider extends GitProvider {

  /**
   * {@inheritdoc}
   */
  public $provider = 'github';

  /**
   * {@inheritdoc}
   */
  public function getToken() {
    // Ask for a GitHub token if one is not available.
    $github_token = getenv('GITHUB_TOKEN');
    while (empty($github_token)) {
      $github_token = $this->io()->askHidden("Please generate a GitHub personal access token by visiting the page:\n\n    https://github.com/settings/tokens\n\n For more information, see:\n\n    https://help.github.com/articles/creating-an-access-token-for-command-line-use.\n\n Give it the 'repo' (required) and 'delete-repo' (optional) scopes.\n Then, enter it here:");
      $github_token = trim($github_token);
      putenv("GITHUB_TOKEN=$github_token");

      // Validate that the GitHub token looks correct. If not, prompt again.
      if ((strlen($github_token) < 40) || preg_match('#[^0-9a-fA-F]#', $github_token)) {
        $this->log()->warning('GitHub tokens should be 40-character strings containing only the letters a-f and digits (0-9). Please enter your token again.');
        $github_token = '';
      }
    }

    return $github_token;
  }

  /**
   * {@inheritdoc}
   */
  public function create($source, $target, $git_org, $git_token, $stability) {
    // We need a different URL here if $github_org is an org; if no
    // org is provided, then we use a simpler URL to create a repository
    // owned by the currently-authenitcated user.
    $createRepoUrl = "orgs/$git_org/repos";
    $target_org = $git_org;
    if (empty($github_org)) {
      $createRepoUrl = 'user/repos';
      $userData = $this->curl('user', [], $git_token);
      $target_org = $userData['login'];
    }
    $target_project = "$target_org/$target";

    $source_project = $this->sourceProjectFromSource($source);
    $tmpsitedir = $this->tempdir('local-site');

    $local_site_path = "$tmpsitedir/$target";

    $this->log()->notice('Creating project and resolving dependencies.');

    // If the source is 'org/project:dev-branch', then automatically
    // set the stability to 'dev'.
    if (empty($stability) && preg_match('#:dev-#', $source)) {
      $stability = 'dev';
    }
    // Pass in --stability to `composer create-project` if user requested it.
    $stability_flag = empty($stability) ? '' : "--stability $stability";

    // TODO: Do we need to remove $local_site_path/.git? (-n should obviate this need)
    $this->passthru("composer create-project $source $local_site_path -n $stability_flag");

    // Create a GitHub repository
    $this->log()->notice('Creating repository {repo} from {source}', ['repo' => $target_project, 'source' => $source]);
    $postData = ['name' => $target];
    $result = $this->curl($createRepoUrl, $postData, $git_token);

    // Create a git repository. Add an origin just to have the data there
    // when collecting the build metadata later. We use the 'pantheon'
    // remote when pushing.
    $this->passthru("git -C $local_site_path init");
    $this->passthru("git -C $local_site_path remote add origin 'git@github.com:{$target_project}.git'");

    return [$target_project, $local_site_path];
  }

  /**
   * {@inheritdoc}
   */
  public function push($git_token, $target_project, $repositoryDir) {
    $this->log()->notice('Push initial commit to GitHub');
    $remote_url = "https://$git_token:x-oauth-basic@github.com/${target_project}.git";
    $this->passthruRedacted("git -C $repositoryDir push --progress $remote_url master", $git_token);
  }

  /**
   * {@inheritdoc}
   */
  public function site($target_project) {
    return "https://github.com/" . $target_project;
  }

  /**
   * {@inheritdoc}
   */
  public function desiredURL($target_project) {
    return "git@github.com:{$target_project}.git";
  }

  /**
   * {@inheritdoc}
   */
  public function delete($target_project, $git_token) {
    $ch = $this->createGitHubDeleteChannel("repos/$target_project", $git_token);
    $data = $this->execCurlRequest($ch, 'GitHub');
  }

  public function preserveEnvsWithOpenPRs($remoteUrl, $oldestEnvironments, $multidev_delete_pattern, $auth = '') {
    $project = $this->projectFromRemoteUrl($remoteUrl);
    $branchList = $this->branchesForOpenPullRequests($project, $auth);
    return $this->filterBranches($oldestEnvironments, $branchList, $multidev_delete_pattern);
  }

  protected function branchesForOpenPullRequests($project, $auth = '') {
    $data = $this->curl("repos/$project/pulls?state=open", [], $auth);

    $branchList = array_map(
      function ($item) {
        return $item['head']['ref'];
      },
      $data
    );

    return $branchList;
  }

  protected function createGitHubDeleteChannel($uri, $auth) {
    $ch = $this->createGitHubCurlChannel($uri, $auth);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');

    return $ch;
  }

  protected function curl($uri, $postData = [], $auth = '') {
    $this->log()->notice('Call GitHub API: {uri}', ['uri' => $uri]);
    $ch = $this->createGitHubPostChannel($uri, $postData, $auth);
    return $this->execCurlRequest($ch, 'GitHub');
  }

  protected function createGitHubCurlChannel($uri, $auth = '')
  {
    $url = "https://api.github.com/$uri";
    return $this->createAuthorizationHeaderCurlChannel($url, $auth);
  }

  protected function createGitHubPostChannel($uri, $postData = [], $auth = '')
  {
    $ch = $this->createGitHubCurlChannel($uri, $auth);
    $this->setCurlChannelPostData($ch, $postData);

    return $ch;
  }
}