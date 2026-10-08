<?php

declare(strict_types=1);

namespace Epiclub\Engine;

/**
 * [VAGUE 12] Fournisseur centralisé de configuration.
 *
 * Remplace les `include __DIR__ . '/../../.env.local.php'` dispersés
 * dans le code et les accès directs à `$_ENV[...]`.
 *
 * Le fichier `.env.local.php` est un `return [...]` PHP. Il est lu
 * UNE SEULE FOIS au constructeur (cache en mémoire). Les modifications
 * via set() restent en mémoire jusqu'à un appel explicite à persist().
 *
 * ─── Politique stricte ───
 * - Fichier absent → RuntimeException (l'app ne peut pas démarrer sans config).
 * - Clé absente → $default (pas d'exception, c'est l'appelant qui décide).
 * - get() ne retourne JAMAIS null si une valeur existe (même '' ou 0).
 *
 * @see EnvironmentFileParser Pour l'écriture (hérite du même fichier).
 */
final class ConfigProvider
{
    /** @var array<string, mixed> */
    private array $config = [];

    private string $filePath;

    public function __construct(string $envFile)
    {
        if (!is_file($envFile)) {
            throw new \RuntimeException(sprintf(
                'Configuration file not found: %s',
                $envFile
            ));
        }

        $loaded = require $envFile;

        if (!is_array($loaded)) {
            throw new \RuntimeException(sprintf(
                'Configuration file must return an array: %s',
                $envFile
            ));
        }

        /** @var array<string, mixed> $loaded */
        $this->config = $loaded;
        $this->filePath = $envFile;
    }

    /**
     * Récupère une valeur de configuration.
     *
     * @param string $key     Clé (sensible à la casse, telle que dans le fichier).
     * @param mixed  $default Valeur de repli si la clé est absente.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Indique si une clé existe (même si sa valeur est null).
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->config);
    }

    /**
     * Modifie une valeur EN MÉMOIRE uniquement.
     * Pour persister sur disque, appeler persist() explicitement.
     */
    public function set(string $key, mixed $value): void
    {
        $this->config[$key] = $value;
    }

    /**
     * @return array<string, mixed> Toute la configuration.
     */
    public function all(): array
    {
        return $this->config;
    }

    /**
     * Écrit la configuration actuelle sur disque au format PHP
     * (`return [...]`), via EnvironmentFileParser pour bénéficier
     * de l'échappement var_export() (issue #49).
     *
     * @throws \RuntimeException Si l'écriture échoue.
     */
    public function persist(): void
    {
        $parser = new EnvironmentFileParser($this->filePath);

        // Réinjecte la config en mémoire dans le parser (qui démarre
        // avec un env potentiellement différent si le fichier a changé).
        foreach ($this->config as $key => $value) {
            $parser->set($key, $value);
        }
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }
}