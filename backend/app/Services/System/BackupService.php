<?php

namespace App\Services\System;

use App\Models\BackupRun;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ZipArchive;

class BackupService
{
    public function run(BackupRun $backupRun): BackupRun
    {
        $directory = 'backups/'.Carbon::now()->format('YmdHis').'-'.$backupRun->id;
        $databasePath = "{$directory}/database.sql";
        $filesPath = "{$directory}/files.zip";

        Storage::disk('local')->put($databasePath, $this->databaseDump());
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
            'restored_at' => Carbon::now()->toISOString(),
        ], JSON_THROW_ON_ERROR));

        return [
            'restored' => true,
            'database_path' => $backupRun->database_path,
            'files_path' => $backupRun->files_path,
        ];
    }

    private function databaseDump(): string
    {
        $connection = $this->backupSourceConnection();
        $driver = $connection->getDriverName();
        $tables = $this->tableNames($connection);
        $dump = [
            '-- New LIMS database backup',
            '-- connection: '.$connection->getName(),
            '-- driver: '.$driver,
            '-- created_at: '.Carbon::now()->toISOString(),
            '',
        ];

        foreach ($tables as $table) {
            $dump[] = '-- table: '.$table;
            $dump[] = $this->createStatement($connection, $table);
            $dump[] = '';

            $rows = $connection->table($table)->get();
            foreach ($rows as $row) {
                $values = (array) $row;
                $columns = collect(array_keys($values))->map(fn (string $column): string => $this->quoteIdentifier($column))->implode(', ');
                $quotedValues = collect(array_values($values))->map(fn (mixed $value): string => $this->quoteValue($connection, $value))->implode(', ');
                $dump[] = 'INSERT INTO '.$this->quoteIdentifier($table)." ({$columns}) VALUES ({$quotedValues});";
            }

            $dump[] = '';
        }

        return implode(PHP_EOL, $dump);
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
            return collect($connection->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"))
                ->pluck('name')
                ->all();
        }

        if ($driver === 'mysql') {
            return collect($connection->select('SHOW TABLES'))
                ->map(fn (object $row): string => (string) array_values((array) $row)[0])
                ->sort()
                ->values()
                ->all();
        }

        throw new RuntimeException("Unsupported backup database driver: {$driver}");
    }

    private function createStatement(Connection $connection, string $table): string
    {
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $statement = $connection->selectOne('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', $table]);

            return ($statement?->sql ?? '-- create statement unavailable').';';
        }

        if ($driver === 'mysql') {
            $statement = (array) $connection->selectOne('SHOW CREATE TABLE '.$this->quoteIdentifier($table));

            return ($statement['Create Table'] ?? '-- create statement unavailable').';';
        }

        throw new RuntimeException("Unsupported backup database driver: {$driver}");
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
