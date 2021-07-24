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

    private $pdo;

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

    public function createTable(string $table, array $params = null)
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
        } catch (\PDOException $e) {
            echo $e->getMessage();
        }
    }

    public function insertValues(string $table, array $values)
    {
        if (empty($values)) {
            echo 'No values specified';
            return;
        }

        $count = count($values);
        $increment = 1;

        $sql = 'INSERT INTO ' . $table . ' (';

        foreach ($values as $key => $value) {
            if ($increment == $count) {
                $sql .= $key;
            } else {
                $sql .= $key . ', ';
            }
            $increment++;
        }

        $increment = 1;

        $sql .= ') VALUES (';

        foreach ($values as $key => $value) {
            if ($increment == $count) {
                $sql .= $value . ')';
            } else {
                $sql .= $value . ', ';
            }
            $increment++;
        }

        try {
            $this->pdo->exec($sql);
        } catch (\PDOException $e) {
            echo $e->getMessage();
        }
    }

    public function updateValues(string $table, array $insert, array $where)
    {
        if (empty($insert) || empty($where)) {
            echo 'Missing parameters';
            return;
        }

        $count = count($insert);
        $increment = 1;

        $sql = 'UPDATE ' . $table . ' SET ';

        foreach ($insert as $key => $value) {
            if ($increment == $count) {
                $sql .= $key . '=' . $value;
            } else {
                $sql .= $key . '=' . $value . ', ';
            }

            $increment++;
        }

        $count = count($where);
        $increment = 1;

        $sql .= ' WHERE ' . key($where) . '=' . $where[key($where)];

        if ($count > 1) {
            foreach (array_slice($where, 1) as $key => $value) {
                if ($increment == $count) {
                    $sql .= ' WHERE ' . $key . '=' . $value;
                } else {
                    $sql .= ' AND ' . $key . '=' . $value;
                }

                $increment++;
            }
        }


        try {
            $this->pdo->exec($sql);
        } catch (\PDOException $e) {
            echo $e->getMessage();
        }
    }

    public function __destruct()
    {
        $this->pdo = null;
    }
}
