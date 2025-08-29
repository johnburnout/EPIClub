<?php


function db()
{
    try {
        $db = new \mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
        $db->set_charset("utf8mb4");
        return $db;
    } catch (\mysqli_sql_exception $th) {
        throw new \RuntimeException("Une erreur interne s'est produite. Nous nous excusons pour la gêne occasionnée et travaillons à la résolution du problème.", 1);
    }
}
