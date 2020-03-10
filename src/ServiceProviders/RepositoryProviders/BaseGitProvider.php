<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders;

use Psr\Log\LoggerAwareTrait;
use Pantheon\TerminusBuildTools\Utility\ExecWithRedactionTrait;
use Robo\Config\Config;

/**
 * Provides a base set of functionality for Git Providers.
 */
abstract class BaseGitProvider
{
    use LoggerAwareTrait;
    use ExecWithRedactionTrait;

    protected $config;

    protected $repositoryEnvironment;
    protected $serviceName;
    protected $baseGitUrl;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @return string
     */
    public function getServiceName()
    {
        return $this->serviceName;
    }

    public function getBaseGitUrl()
    {
        return $this->baseGitUrl;
    }

    public function getEnvironment()
    {
        if (!$this->repositoryEnvironment) {
            $this->repositoryEnvironment = (new RepositoryEnvironment())
              ->setServiceName($this->serviceName);
        }
        return $this->repositoryEnvironment;
    }

    protected function execGit($dir, $cmd, $replacements = [], $redacted = [])
    {
        $this->execWithRedaction('git {dir}' . $cmd, ['dir' => "-C $dir "] + $replacements, ['dir' => ''] + $redacted);
    }

    public function commitCode($dir, $comment)
    {
        $this->execGit($dir, 'add -A');
        $this->execGit($dir, 'commit -m [[message]]', ['message' => $comment]);
    }

    public function generateBuildProvidersData($git_service_name, $ci_service_name)
    {
        return [
            'git' => $git_service_name,
            'ci' => $ci_service_name
        ];
    }

    public function writeBuildProvidersFile($git_service_name, $ci_service_name, $repositoryDir)
    {
        $buildMetadataFile = "$repositoryDir/build-providers.json";
        $metadata = $this->generateBuildProvidersData($git_service_name, $ci_service_name);
        $metadataContents = json_encode($metadata, JSON_UNESCAPED_SLASHES);
        file_put_contents($buildMetadataFile, $metadataContents);
    }

    public function alterBuildMetadata(&$buildMetadata)
    {
    }

    public function getSecretValues() {
      return [
        'token' => $this->token($this->tokenKey())
      ];
    }

    public function verifySSHConnect(){
        passthru(sprintf('ssh -T %s', $this->baseGitUrl), $result);
        return $result === 0;
    }

}
