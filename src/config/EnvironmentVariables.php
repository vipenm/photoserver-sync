<?php

namespace PhotoserverSync\Config;

class EnvironmentVariables
{
    private $config;

    public function __construct()
    {
        $this->config = file_get_contents(dirname(realpath("."), 7) . DIRECTORY_SEPARATOR . "config.json");
        $this->config = json_decode($this->config);
    }

    public function getAWSKey()
    {
        return $this->config->aws_key;
    }

    public function getAWSSecret()
    {
        return $this->config->aws_secret;
    }

    public function getAWSBucketName()
    {
        return $this->config->aws_bucket_name;
    }

    public function getAWSRegion()
    {
        return $this->config->aws_region;
    }

    public function getMailUsername()
    {
        return $this->config->mail_username;
    }

    public function getMailPassword()
    {
        return $this->config->mail_password;
    }

    public function getMailHost()
    {
        return $this->config->mail_host;
    }

    public function getMailRecipientEmail()
    {
        return $this->config->mail_recipent_email;
    }

    public function getMailRecipientName()
    {
        return $this->config->mail_recipent_name;
    }

    public function getUsername()
    {
        return $this->config->username;
    }

    public function getPassword()
    {
        return $this->config->password;
    }

    public function getNextcloudUsername()
    {
        return $this->config->nextcloud_username;
    }

    public function getNextcloudPassword()
    {
        return $this->config->nextcloud_password;
    }
}