<?php

namespace App\Services\System;

use App\Models\BackupRun;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use ZipArchive;

class BackupService
{
    public function run(BackupRun $backupRun): BackupRun
    {
        $lock = Cache::lock(
            (string) config('backup.backup.lock.key', 'system-backup'),
            (int) config('backup.backup.lock.seconds', 1800),
        );

        if (! $lock->get()) {
            $this->markFailed($backupRun, 'Another backup is already running.');

            throw new RuntimeException('Another backup is already running.');
        }

        try {
            $backupRun->update([
                'status' => 'running',
                'error_message' => null,
                'started_at' => Carbon::now(),
                'finished_at' => null,
            ]);

            $directory = 'backups/'.Carbon::now()->format('YmdHis').'-'.$backupRun->id;
            $databasePath = "{$directory}/database.sql";
            $filesPath = "{$directory}/files.zip";

            $this->writeDatabaseDump($databasePath);
            $this->writeFilesArchive($filesPath);

            $size = filesize(Storage::disk('local')->path($databasePath)) + filesize(Storage::disk('local')->path($filesPath));

            $backupRun->update([
                'status' => 'succeeded',
                'database_path' => $databasePath,
                'files_path' => $filesPath,
                'size_bytes' => $size,
                'finished_at' => Carbon::now(),
            ]);

            return $backupRun->fresh();
        } catch (Throwable $throwable) {
            $this->markFailed($backupRun, $throwable->getMessage());

            throw $throwable;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{restored: bool, database_path: string, files_path: string}
     */
    public function restore(BackupRun $backupRun): array
    {
        if ($backupRun->status !== 'succeeded' || ! $backupRun->database_path || ! $backupRun->files_path) {
            throw new RuntimeException('Backup run is not restorable.');
        }

        if (! Storage::disk('local')->exists($backupRun->database_path) || ! Storage::disk('local')->exists($backupRun->files_path)) {
            throw new RuntimeException('Backup files are missing.');
        }

        Storage::disk('local')->put('backups/restores/'.$backupRun->id.'.json', json_encode([
            'backup_run_id' => $backupRun->id,
            'database_path' => $backupRun->database_path,
            'files_path' => $backupRun->files_path,
            'restored_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ], JSON_THROW_ON_ERROR));

        return [
            'restored' => true,
            'database_path' => $backupRun->database_path,
            'files_path' => $backupRun->files_path,
        ];
    }

    private function writeDatabaseDump(string $path): void
    {
        $connection = $this->backupSourceConnection();
        $driver = $connection->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->writeMysqlDatabaseDump($connection, $path);

            return;
        }

        if ($driver === 'sqlite') {
            $this->writeSqliteDatabaseDump($connection, $path);

            return;
        }

        throw new RuntimeException("Unsupported backup database driver: {$driver}");
    }

    private function writeSqliteDatabaseDump(Connection $connection, string $path): void
    {
        $absolutePath = Storage::disk('local')->path($path);
        $directory = dirname($absolutePath);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $handle = fopen($absolutePath, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Unable to create database backup dump.');
        }

        $header = [
            '-- New LIMS database backup',
            '-- connection: '.$connection->getName(),
            '-- driver: '.$connection->getDriverName(),
            '-- created_at: '.Carbon::now()->format('Y-m-d H:i:s'),
            '',
        ];

        try {
            fwrite($handle, implode(PHP_EOL, $header).PHP_EOL);

            foreach ($this->tableNames($connection) as $table) {
                fwrite($handle, '-- table: '.$table.PHP_EOL);
                fwrite($handle, $this->createStatement($connection, $table).PHP_EOL.PHP_EOL);

                foreach ($connection->table($table)->cursor() as $row) {
                    $values = (array) $row;
                    $columns = collect(array_keys($values))->map(fn (string $column): string => $this->quoteIdentifier($column))->implode(', ');
                    $quotedValues = collect(array_values($values))->map(fn (mixed $value): string => $this->quoteValue($connection, $value))->implode(', ');
                    fwrite($handle, 'INSERT INTO '.$this->quoteIdentifier($table)." ({$columns}) VALUES ({$quotedValues});".PHP_EOL);
                }

                fwrite($handle, PHP_EOL);
            }
        } finally {
            fclose($handle);
        }
    }

    private function writeMysqlDatabaseDump(Connection $connection, string $path): void
    {
        $absolutePath = Storage::disk('local')->path($path);
        $directory = dirname($absolutePath);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $config = config("database.connections.{$connection->getName()}");
        $database = (string) ($config['database'] ?? '');

        if ($database === '') {
            throw new RuntimeException('Backup database name is not configured.');
        }

        $command = [
            (string) config('backup.backup.source.database_dump.mysql_binary', 'mysqldump'),
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--routines',
            '--triggers',
            '--events',
            '--default-character-set='.($config['charset'] ?? 'utf8mb4'),
            '--result-file='.$absolutePath,
        ];

        if (! empty($config['unix_socket'])) {
            $command[] = '--socket='.$config['unix_socket'];
        } else {
            $command[] = '--host='.($config['host'] ?? '127.0.0.1');
            $command[] = '--port='.(string) ($config['port'] ?? 3306);
        }

        if (($config['username'] ?? '') !== '') {
            $command[] = '--user='.$config['username'];
        }

        foreach ($this->excludedTables() as $table) {
            $command[] = '--ignore-table='.$database.'.'.$table;
        }

        $command[] = $database;

        $result = Process::timeout((int) config('backup.backup.source.database_dump.timeout', 900))
            ->env(array_filter([
                'MYSQL_PWD' => $config['password'] ?? null,
            ], fn (mixed $value): bool => $value !== null && $value !== ''))
            ->run($command);

        if ($result->failed()) {
            throw new RuntimeException(trim($result->errorOutput()) ?: 'mysqldump failed.');
        }

        if (! is_file($absolutePath)) {
            throw new RuntimeException('mysqldump did not create a database backup file.');
        }
    }

    private function markFailed(BackupRun $backupRun, string $message): void
    {
        $backupRun->update([
            'status' => 'failed',
            'error_message' => $message,
            'finished_at' => Carbon::now(),
        ]);
    }

    private function backupSourceConnection(): Connection
    {
        return DB::connection(config('backup.backup.source.database_connection'));
    }

    /**
     * @return array<int, string>
     */
    private function tableNames(Connection $connection): array
    {
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $tables = collect($connection->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"))
                ->pluck('name')
                ->all();

            return $this->withoutExcludedTables($tables);
        }

        throw new RuntimeException("Unsupported backup database driver: {$driver}");
    }

    /**
     * @return array<int, string>
     */
    private function excludedTables(): array
    {
        return collect(config('backup.backup.source.exclude_tables', []))
            ->map(fn (mixed $table): string => (string) $table)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $tables
     * @return array<int, string>
     */
    private function withoutExcludedTables(array $tables): array
    {
        $excludedTables = collect($this->excludedTables())
            ->map(fn (string $table): string => strtolower($table))
            ->flip();

        return collect($tables)
            ->reject(fn (string $table): bool => $excludedTables->has(strtolower($table)))
            ->values()
            ->all();
    }

    private function createStatement(Connection $connection, string $table): string
    {
        $statement = $connection->selectOne('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', $table]);

        return ($statement?->sql ?? '-- create statement unavailable').';';
    }

    private function writeFilesArchive(string $path): void
    {
        $absolutePath = Storage::disk('local')->path($path);
        $directory = dirname($absolutePath);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $zip = new ZipArchive;
        if ($zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create backup file archive.');
        }

        $root = Storage::disk('local')->path('');
        $added = false;

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $relativePath = ltrim(str_replace($root, '', $file->getPathname()), DIRECTORY_SEPARATOR);
            if ($relativePath === '' || str_starts_with($relativePath, 'backups'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $zip->addFile($file->getPathname(), str_replace(DIRECTORY_SEPARATOR, '/', $relativePath));
            $added = true;
        }

        if (! $added) {
            $zip->addFromString('manifest.json', json_encode(['files' => []], JSON_THROW_ON_ERROR));
        }

        $zip->close();
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function quoteValue(Connection $connection, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return $connection->getPdo()->quote((string) $value);
    }
}
