<?php

namespace Epiclub\Engine;


class DatabaseConnection
{
    protected \PDO $db;

    public function __construct()
    {
        $this->db = new \PDO("mysql:host=$_ENV[DB_HOST];dbname=$_ENV[DB_NAME];charset=utf8", $_ENV['DB_USER'], $_ENV['DB_PASS']);
        $this->db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    }
}
