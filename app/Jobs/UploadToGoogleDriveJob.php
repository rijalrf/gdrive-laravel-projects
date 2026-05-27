<?php

namespace App\Jobs;

use App\Services\GoogleDriveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class UploadToGoogleDriveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $tempFilename;
    protected $targetPath;
    protected $compress;
    protected $quality;

    /**
     * Create a new job instance.
     *
     * @param string $tempFilename
     * @param string $targetPath
     * @param bool $compress
     * @param int $quality
     */
    public function __construct(string $tempFilename, string $targetPath, bool $compress, int $quality)
    {
        $this->tempFilename = $tempFilename;
        $this->targetPath = $targetPath;
        $this->compress = $compress;
        $this->quality = $quality;
    }

    /**
     * Execute the job.
     *
     * @param GoogleDriveService $driveService
     * @return void
     */
    public function handle(GoogleDriveService $driveService)
    {
        Log::info("Async Upload Job execution started for: " . $this->targetPath);

        if (!Storage::exists($this->tempFilename)) {
            Log::error("Temp file for async upload does not exist: " . $this->tempFilename);
            return;
        }

        $localPath = Storage::path($this->tempFilename);

        try {
            $result = $driveService->uploadSync($localPath, $this->targetPath, $this->compress, $this->quality);
            Log::info("Async Upload Job success for: {$this->targetPath}. Google Drive File ID: " . $result['id']);
        } catch (Exception $e) {
            Log::error("Async Upload Job failed for {$this->targetPath}: " . $e->getMessage());
            throw $e;
        } finally {
            // Delete the temporary file from Laravel local storage
            if (Storage::exists($this->tempFilename)) {
                Storage::delete($this->tempFilename);
                Log::info("Cleaned up temp file after job: " . $this->tempFilename);
            }
        }
    }
}
