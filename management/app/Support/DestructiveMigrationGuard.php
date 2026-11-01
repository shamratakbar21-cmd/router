<?php

namespace App\Support;

use RuntimeException;

class DestructiveMigrationGuard
{
    private const array PROTECTED_COMMANDS = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
    ];

    public function ensureAllowed(string $commandName): void
    {
        if (! in_array($commandName, self::PROTECTED_COMMANDS, true)) {
            return;
        }

        if ($this->usesDisposableDatabase()) {
            return;
        }

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        throw new RuntimeException(
            "{$commandName} is restricted to disposable test databases. ".
            "Current connection [{$connection}] points at [{$database}].",
        );
    }

    private function usesDisposableDatabase(): bool
    {
        if (app()->environment('testing')) {
            return true;
        }

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($database === '' || $database === ':memory:') {
            return true;
        }

        if ($connection === 'sqlite') {
            $normalizedDatabase = $this->normalizePath($database);

            return str_starts_with($normalizedDatabase, $this->normalizePath(database_path('testing/')))
                || str_starts_with($normalizedDatabase, $this->normalizePath(storage_path('testing/')))
                || str_starts_with($normalizedDatabase, $this->normalizePath(sys_get_temp_dir().DIRECTORY_SEPARATOR))
                || preg_match('/(?:^|\/)(?:test|testing|tmp|temp|sandbox)[^\/]*\.sqlite$/i', $normalizedDatabase) === 1;
        }

        return preg_match('/(?:^|[_-])(test|testing|tmp|temp|sandbox|ci)(?:[_-]|$)/i', $database) === 1;
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/').'/';
    }
}
