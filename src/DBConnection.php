<?php

namespace PhotoserverSync;

use PhotoserverSync\Config\EnvironmentVariables;

class DBConnection
{
    private $config;

    private $mysql_host;

    private $mysql_database;

    private $mysql_username;

    private $mysql_password;

    private $pdo = null;

    public function __construct()
    {
        $this->config = new EnvironmentVariables();
        $this->mysql_host = $this->config->getMysqlHost();
        $this->mysql_database = $this->config->getMysqlDatabase();
        $this->mysql_username = $this->config->getMysqlUsername();
        $this->mysql_password = $this->config->getMysqlPassword();

        $conStr = sprintf("mysql:host=%s;dbname=%s", $this->mysql_host, $this->mysql_database);
        try {
            $this->pdo = new \PDO($conStr, $this->mysql_username, $this->mysql_password);
        } catch (\PDOException $e) {
            echo $e->getMessage();
        }
    }

    public function createTable(string $table, object $params = null)
    {
        $sql = 'CREATE TABLE IF NOT EXISTS ' . $table . ' (' .
        'id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,';
        if ($params) {
            foreach ($params as $key => $value) {
                $sql .= ' ' . $key . ' ' . $value . ',';
            }
        }
        $sql .= ' creation_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,' .
        ' last_modified TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)';

        try {
            $this->pdo->exec($sql);
            $this->pdo = null;
        } catch (\PDOException $e) {
            echo $e->getMessage();
            $this->pdo = null;
        }
    }
}
