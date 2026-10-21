<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine\Support;

/**
 * PDO SQLite en mémoire qui imite les requêtes d'introspection MySQL
 * utilisées par MySqlDatabaseDumper (SHOW FULL TABLES, SHOW CREATE TABLE,
 * SHOW COLUMNS) et l'échappement de PDO::quote() côté MySQL.
 *
 * Les instructions envoyées par exec() sont enregistrées ; elles ne sont
 * exécutées que si $executer est vrai (préparation des données).
 */
final class FakeMysqlPdo extends \PDO
{
    /** @var string[] */
    public array $executees = [];

    public bool $executer = true;

    /** @var array<string, array<string, string>> table => colonne => Extra */
    public array $extras = [];

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        if ($query === 'SHOW FULL TABLES') {
            $query = "SELECT name, 'BASE TABLE' FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name";
        } elseif (preg_match('/^SHOW CREATE TABLE `(\w+)`$/', $query, $m)) {
            $query = "SELECT name, sql FROM sqlite_master WHERE name = '" . $m[1] . "'";
        } elseif (preg_match('/^SHOW COLUMNS FROM `(\w+)`$/', $query, $m)) {
            $cases = '';
            foreach ($this->extras[$m[1]] ?? [] as $col => $extra) {
                $cases .= " WHEN '" . $col . "' THEN '" . $extra . "'";
            }
            $extra = $cases === '' ? "''" : 'CASE name' . $cases . " ELSE '' END";
            $query = 'SELECT name AS Field, type AS Type, ' . $extra . " AS Extra FROM pragma_table_info('" . $m[1] . "')";
        }
        return parent::query($query);
    }

    public function exec(string $statement): int|false
    {
        $this->executees[] = $statement;
        if (!$this->executer || str_starts_with($statement, 'SET ')) {
            return 0;
        }
        return parent::exec($statement);
    }

    public function quote(string $string, int $type = \PDO::PARAM_STR): string|false
    {
        return "'" . strtr($string, [
            '\\'   => '\\\\',
            "\0"   => '\\0',
            "\n"   => '\\n',
            "\r"   => '\\r',
            "'"    => "\\'",
            '"'    => '\\"',
            "\x1a" => '\\Z',
        ]) . "'";
    }
}
