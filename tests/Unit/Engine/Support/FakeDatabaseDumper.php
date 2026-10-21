<?php

declare(strict_types=1);

namespace Epiclub\Tests\Unit\Engine\Support;

use Epiclub\Engine\DatabaseDumperInterface;

/**
 * Base de données simulée en mémoire pour tester SauvegardeService
 * sans serveur MySQL : l'« export » est un JSON de $tables.
 */
final class FakeDatabaseDumper implements DatabaseDumperInterface
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $tables = [];

    /** @var string[] */
    public array $migrations = ['001_initial'];

    /** Nombre d'imports à faire échouer (après avoir vidé la base). */
    public int $echecsImport = 0;

    public int $imports = 0;

    public function exporter(string $fichierSql): array
    {
        file_put_contents($fichierSql, "-- fake\n" . json_encode([
            'tables'     => $this->tables,
            'migrations' => $this->migrations,
        ]));
        return array_map('count', $this->tables);
    }

    public function importer(string $fichierSql): void
    {
        $this->imports++;
        $contenu = (string) file_get_contents($fichierSql);
        if (!str_starts_with($contenu, "-- fake\n")) {
            throw new \RuntimeException('pas un export');
        }
        $this->tables = [];
        if ($this->echecsImport > 0) {
            $this->echecsImport--;
            throw new \RuntimeException('échec simulé');
        }
        $data = json_decode(substr($contenu, 8), true);
        $this->tables = $data['tables'];
        $this->migrations = $data['migrations'];
    }

    public function migrationsAppliquees(): array
    {
        return $this->migrations;
    }
}
