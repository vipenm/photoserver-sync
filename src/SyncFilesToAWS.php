<?php

namespace PhotoserverSync;

use PhotoserverSync\Config\EnvironmentVariables;

use Aws\S3\S3Client;

class SyncFilesToAWS
{
  private $client;

  private $aws_key;

  private $aws_secret;

  private $aws_bucket_name;

  private $aws_region;

  private $config;

  public function __construct()
  {
    $this->config = new EnvironmentVariables();
    $this->aws_key = $this->config->getAWSKey();
    $this->aws_secret = $this->config->getAWSSecret();
    $this->aws_bucket_name = $this->config->getAWSBucketName();
    $this->aws_region = $this->config->getAWSRegion();

    $this->client = new S3Client([
      'version' => 'latest',
      'region' => $this->aws_region,
      'credentials' => [
        'key' => $this->aws_key,
        'secret' => $this->aws_secret,
      ],
      'http'    => [
        'verify' => dirname(__DIR__, 1) . '\config\cacert.pem'
      ]
    ]);
  }

  public function uploadToS3($file, $filename, $metadata = '')
  {
    $this->client->putObject(array(
      'Bucket' => $this->aws_bucket_name,
      'SourceFile' => $file,
      'Key' => $filename,
      'Metadata'   => $metadata
    ));
  }

}