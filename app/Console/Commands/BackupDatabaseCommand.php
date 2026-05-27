<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\GoogleDriveService;
use Illuminate\Support\Facades\Log;
use Exception;

class BackupDatabaseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:backup {--async : Upload backup asynchronously}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backup the PostgreSQL database and upload it to Google Drive';

    /**
     * Execute the console command.
     *
     * @param GoogleDriveService $driveService
     * @return int
     */
    public function handle(GoogleDriveService $driveService)
    {
        $this->info('Starting database backup...');

        $connection = config('database.default');
        if ($connection !== 'pgsql') {
            $this->error('Database connection is not configured to pgsql. Current: ' . $connection);
            return 1;
        }

        $host = config('database.connections.pgsql.host');
        $port = config('database.connections.pgsql.port');
        $database = config('database.connections.pgsql.database');
        $username = config('database.connections.pgsql.username');
        $password = config('database.connections.pgsql.password');

        if (!$database) {
            $this->error('Database name is not configured.');
            return 1;
        }

        $filename = 'backup_' . $database . '_' . date('Y-m-d_H-i-s') . '.sql.gz';
        $tempPath = tempnam(sys_get_temp_dir(), 'db_backup_');

        if (!$tempPath) {
            $this->error('Failed to create a temporary file.');
            return 1;
        }

        // Use piping to gzip to compress the backup directly.
        // For PostgreSQL, we use PGPASSWORD environment variable and pg_dump.
        $dumpCmd = sprintf(
            'PGPASSWORD=%s pg_dump -h %s -p %s -U %s %s | gzip > %s',
            escapeshellarg($password),
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($database),
            escapeshellarg($tempPath)
        );

        $cmd = sprintf('bash -o pipefail -c %s', escapeshellarg($dumpCmd));

        $output = [];
        $resultCode = null;

        $this->info('Dumping database...');
        exec($cmd, $output, $resultCode);

        if ($resultCode !== 0) {
            $this->error('Failed to dump database. Exec command exited with code: ' . $resultCode);
            Log::error('Database backup failed: pg_dump exited with code ' . $resultCode);
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
            return 1;
        }

        $this->info('Uploading to Google Drive...');

        // Google Drive folder path
        $folderPath = env('GOOGLE_DRIVE_FOLDER_PATH', 'MKAS LARAVEL STORAGE');
        $targetPath = trim($folderPath, '/') . '/BACKUPS/' . $filename;
        $async = $this->option('async');

        try {
            $uploadResult = $driveService->uploadImage($tempPath, $targetPath, [
                'async' => $async,
                'compress' => false,
            ]);

            if ($uploadResult['status'] === 'queued') {
                $this->info('Backup upload is queued in the background.');
                Log::info('Database backup upload queued: ' . $targetPath);
            } else {
                $this->info('Backup uploaded successfully! ID: ' . ($uploadResult['id'] ?? 'N/A'));
                Log::info('Database backup successfully uploaded to Google Drive: ' . $targetPath);
            }
        } catch (Exception $e) {
            $this->error('Upload failed: ' . $e->getMessage());
            Log::error('Database backup upload failed: ' . $e->getMessage());
            return 1;
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }

        return 0;
    }
}
