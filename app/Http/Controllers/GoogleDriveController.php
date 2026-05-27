<?php

namespace App\Http\Controllers;

use App\Services\GoogleDriveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

class GoogleDriveController extends Controller
{
    protected $driveService;

    public function __construct(GoogleDriveService $driveService)
    {
        $this->driveService = $driveService;
    }

    /**
     * Return the Google Drive Service status / health check in JSON.
     */
    public function index(Request $request)
    {
        $status = 'online';
        $gdriveConnection = 'connected';
        $errorMessage = null;

        try {
            // Attempt to list files in root path to check connectivity
            $this->driveService->listFiles('');
        } catch (Exception $e) {
            $status = 'degraded';
            $gdriveConnection = 'failed';
            $errorMessage = $e->getMessage();
        }

        return response()->json([
            'success' => $status === 'online',
            'status' => $status,
            'service' => 'gdrive-backup-api',
            'version' => '1.0.0',
            'google_drive_connection' => $gdriveConnection,
            'error_message' => $errorMessage,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Upload an image/file to Google Drive.
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:102400', // Max 10MB
            'target_path' => 'nullable|string',
        ]);

        $file = $request->file('file');
        
        // Use default sample path if not provided
        $targetPath = $request->get('target_path', 'MKAS LARAVEL STORAGE/TRANSACTIONS/' . $file->getClientOriginalName());
        
        // If the path ends with a directory and no filename, append the original filename
        if (str_ends_with($targetPath, '/')) {
            $targetPath .= $file->getClientOriginalName();
        } elseif (!str_contains(basename($targetPath), '.')) {
            // If target_path is just a directory name (e.g. MKAS LARAVEL STORAGE/TRANSACTIONS)
            $targetPath .= '/' . $file->getClientOriginalName();
        }

        $async = $request->has('async') ? filter_var($request->get('async'), FILTER_VALIDATE_BOOLEAN) : (config('gdrive.upload_mode') === 'async');
        $compress = $request->has('compress') ? filter_var($request->get('compress'), FILTER_VALIDATE_BOOLEAN) : config('gdrive.compress.enabled');
        $quality = $request->get('quality', config('gdrive.compress.quality', 75));

        try {
            $result = $this->driveService->uploadImage($file->getPathname(), $targetPath, [
                'async' => $async,
                'compress' => $compress,
                'quality' => (int)$quality,
            ]);

            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Upload completed successfully.',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            Log::error("Controller Upload error: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Upload failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Preview / Stream file contents from Google Drive.
     */
    public function preview(Request $request)
    {
        $path = $request->get('path');
        if (empty($path)) {
            return response()->json(['error' => 'Path parameter is required'], 400);
        }

        $maxRetries = 3;
        $retryDelay = 2; // seconds
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                $fileData = $this->driveService->getImage($path);
                
                return response($fileData['content'], 200, [
                    'Content-Type' => $fileData['mimeType'],
                    'Content-Length' => $fileData['size'],
                    'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
                    'Cache-Control' => 'private, max-age=86400',
                ]);
            } catch (Exception $e) {
                $attempt++;
                if ($attempt >= $maxRetries) {
                    Log::error("Controller Preview error after {$attempt} attempts: " . $e->getMessage());
                    return response()->json(['error' => 'Could not retrieve file: ' . $e->getMessage()], 404);
                }
                
                Log::warning("Preview attempt {$attempt} failed, retrying in {$retryDelay}s... Path: {$path}");
                sleep($retryDelay);
            }
        }
     }

    /**
     * Trigger database backup via API.
     */
    public function backup(Request $request)
    {
        $async = $request->has('async') ? filter_var($request->get('async'), FILTER_VALIDATE_BOOLEAN) : false;

        try {
            // Run backup command
            \Illuminate\Support\Facades\Artisan::call('db:backup', [
                '--async' => $async
            ]);

            $output = \Illuminate\Support\Facades\Artisan::output();

            return response()->json([
                'success' => true,
                'message' => 'Database backup command triggered successfully.',
                'output' => trim($output)
            ]);
        } catch (Exception $e) {
            Log::error("API Backup error: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Backup failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
