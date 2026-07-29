<?php

namespace Redcodede\CookieLessTracking\Console\Commands;

use Illuminate\Console\Command;
use Redcodede\CookieLessTracking\CookieLessTracking;
use Redcodede\CookieLessTracking\History\HistoryCompactor;
use Redcodede\CookieLessTracking\History\HistorySchema;
use RuntimeException;
use Throwable;

class CompactTrackingHistory extends Command
{
    protected $signature = 'cookie-less-tracking:compact
        {--before= : Archive completed days before this YYYY-MM-DD date}
        {--dry-run : Analyze eligible data without writing or deleting anything}
        {--backup : Create a backup even when history already exists}
        {--no-backup : Explicitly skip the automatic backup before the first destructive run}
        {--vacuum : Rebuild SQLite after compaction to return free pages to the filesystem}';

    protected $description = 'Aggregate old tracking events into daily history and delete verified raw rows';

    public function handle(): int
    {
        $databasePath = database_path('tracking.sqlite');

        if (! is_file($databasePath)) {
            $this->error("Tracking database not found at {$databasePath}.");

            return self::FAILURE;
        }

        try {
            $pdo = CookieLessTracking::getPDO();
            $timezone = (string) config('app.timezone', 'UTC');
            $compactor = new HistoryCompactor(
                $pdo,
                $timezone,
                (bool) config('cookie-less-tracking.preserve_query_strings_in_history', false),
            );
            $cutoff = (string) ($this->option('before')
                ?: $compactor->defaultCutoff((int) config('cookie-less-tracking.retention_months', 3)));
            $dryRun = (bool) $this->option('dry-run');
            $needsFirstBackup = ! HistorySchema::hasArchivedDays($pdo);

            if ($this->option('backup') && $this->option('no-backup')) {
                throw new RuntimeException('The --backup and --no-backup options cannot be combined.');
            }

            $preview = ($dryRun || $needsFirstBackup)
                ? $compactor->compact($cutoff, true)
                : null;
            $shouldBackup = ! $dryRun
                && ! $this->option('no-backup')
                && ($this->option('backup') || ($needsFirstBackup && $preview['raw_rows'] > 0));

            if ($shouldBackup) {
                $backupPath = $this->backup($pdo, $databasePath);
                $this->info("Backup created: {$backupPath}");
            } elseif (! $dryRun && $needsFirstBackup && $preview['raw_rows'] > 0) {
                $this->warn('The first compaction is running without a backup because --no-backup was supplied.');
            }

            $result = $dryRun ? $preview : $compactor->compact($cutoff);

            if (! $dryRun && $result['raw_rows'] > 0 && ! $this->checkpointWal($pdo)) {
                $this->warn('The SQLite WAL could not be fully truncated because another connection was active.');
            }

            $this->table(
                ['Cutoff', 'Timezone', 'Days', 'Raw rows', 'Reportable events', 'History rows', 'Mode'],
                [[
                    $result['cutoff_day'],
                    $result['timezone'],
                    $result['days'],
                    $result['raw_rows'],
                    $result['analytics_events'],
                    $result['history_rows'],
                    $dryRun ? 'dry run' : 'compacted',
                ]],
            );

            if (! $dryRun && $this->option('vacuum')) {
                $this->vacuum($pdo, $databasePath);
                $this->info('SQLite VACUUM completed.');
            } elseif (! $dryRun && $result['raw_rows'] > 0) {
                $this->line('Deleted pages remain reusable inside SQLite. Use --vacuum during a maintenance window to shrink the file.');
            }

            if (! $dryRun) {
                $deletedBackups = $this->pruneBackups();
                if ($deletedBackups > 0) {
                    $this->line("Deleted {$deletedBackups} expired tracking backup(s).");
                }
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function backup(\PDO $pdo, string $databasePath): string
    {
        $integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();

        if ($integrity !== 'ok') {
            throw new RuntimeException('Source database integrity check failed; compaction was cancelled.');
        }

        $directory = (string) config(
            'cookie-less-tracking.backup_directory',
            database_path('backups/cookie-less-tracking'),
        );
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create backup directory {$directory}.");
        }

        $freeBytes = disk_free_space($directory);
        $databaseBytes = filesize($databasePath);
        if ($freeBytes !== false && $databaseBytes !== false && $freeBytes < $databaseBytes * 1.1) {
            throw new RuntimeException('Not enough free disk space to create the required tracking backup.');
        }

        $path = rtrim($directory, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .'tracking-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sqlite';
        $pdo->exec('VACUUM INTO '.$pdo->quote($path));

        $backup = new \PDO('sqlite:'.$path);
        if ($backup->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException("Backup integrity check failed for {$path}.");
        }

        return $path;
    }

    private function vacuum(\PDO $pdo, string $databasePath): void
    {
        $freeBytes = disk_free_space(dirname($databasePath));
        $databaseBytes = filesize($databasePath);

        if ($freeBytes !== false && $databaseBytes !== false && $freeBytes < $databaseBytes * 2.1) {
            throw new RuntimeException('Not enough free disk space to run VACUUM safely.');
        }

        $pdo->exec('VACUUM');

        if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('Database integrity check failed after VACUUM.');
        }
    }

    private function pruneBackups(): int
    {
        $directory = (string) config(
            'cookie-less-tracking.backup_directory',
            database_path('backups/cookie-less-tracking'),
        );
        $retentionDays = max(1, (int) config('cookie-less-tracking.backup_retention_days', 7));

        if (! is_dir($directory)) {
            return 0;
        }

        $deleted = 0;
        $expiresBefore = time() - ($retentionDays * 86400);

        foreach (glob(rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'tracking-*.sqlite') ?: [] as $path) {
            if (is_file($path) && filemtime($path) < $expiresBefore && unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function checkpointWal(\PDO $pdo): bool
    {
        $result = $pdo->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetch(\PDO::FETCH_NUM);

        return ! $result || (int) $result[0] === 0;
    }
}
