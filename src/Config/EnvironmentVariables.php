<?php

namespace PhotoserverSync\Config;

class EnvironmentVariables
{
    private $config;

    public function __construct()
    {
        $this->config = file_get_contents(dirname(__FILE__, 3) . DIRECTORY_SEPARATOR . "config.json");
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

    public function getMysqlHost()
    {
        return $this->config->mysql_host;
    }

    public function getMysqlDatabase()
    {
        return $this->config->mysql_database;
    }

    public function getMysqlUsername()
    {
        return $this->config->mysql_username;
    }

    public function getMysqlPassword()
    {
        return $this->config->mysql_password;
    }

    public function getKnownImagesPath()
    {
        return $this->config->known_images_path;
    }
}
