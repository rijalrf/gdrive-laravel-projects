<?php

namespace App\Services;

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class GoogleDriveService
{
    protected $client;
    protected $service;
    protected $parentFolderId;

    /**
     * Constructor to initialize Google Client.
     *
     * @param Client|null $client
     */
    public function __construct(?Client $client = null)
    {
        $this->client = $client ?: new Client();
        $this->client->setScopes([Drive::DRIVE]);

        $mode = config('gdrive.credentials.mode', 'file');

        if ($mode === 'refresh_token') {
            $clientId = config('gdrive.credentials.client_id');
            $clientSecret = config('gdrive.credentials.client_secret');
            $refreshToken = config('gdrive.credentials.refresh_token');

            if ($clientId && $clientSecret && $refreshToken) {
                $this->client->setClientId($clientId);
                $this->client->setClientSecret($clientSecret);
                $this->client->refreshToken($refreshToken);
            } else {
                Log::warning("Google Drive OAuth credentials (client_id, client_secret, refresh_token) are missing or incomplete.");
            }
        } elseif ($mode === 'json' && config('gdrive.credentials.json_string')) {
            $credentials = json_decode(config('gdrive.credentials.json_string'), true);
            if (is_array($credentials)) {
                $this->client->setAuthConfig($credentials);
            } else {
                Log::warning("Google Drive credentials JSON is invalid.");
            }
        } else {
            $path = config('gdrive.credentials.file_path');
            if (file_exists($path)) {
                $this->client->setAuthConfig($path);
            } else {
                // If env contains a JSON directly, try to parse it
                $jsonEnv = env('GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON');
                if ($jsonEnv && (str_starts_with($jsonEnv, '{') || str_starts_with($jsonEnv, '['))) {
                    $credentials = json_decode($jsonEnv, true);
                    if (is_array($credentials)) {
                        $this->client->setAuthConfig($credentials);
                    }
                } else {
                    Log::warning("Google Drive credentials file not found at: {$path}");
                }
            }
        }

        $this->service = new Drive($this->client);
        $this->parentFolderId = config('gdrive.parent_folder_id');
    }

    /**
     * Resolve the parent folder ID for a given path of directories, creating folders if they don't exist.
     * For example, if path is "MKAS LARAVEL STORAGE/TRANSACTIONS/file.jpg",
     * folders are ["MKAS LARAVEL STORAGE", "TRANSACTIONS"].
     *
     * @param string $path
     * @return string|null
     */
    public function resolveFolderIdForPath(string $path): ?string
    {
        $parts = explode('/', trim($path, '/'));
        
        // The last element is the file name, not a folder
        if (count($parts) > 1) {
            array_pop($parts);
        } else {
            // No subdirectories in path
            return $this->parentFolderId;
        }

        $currentParentId = $this->parentFolderId;

        foreach ($parts as $folderName) {
            $folderName = trim($folderName);
            if (empty($folderName)) {
                continue;
            }

            $folderId = $this->findFolder($folderName, $currentParentId);

            if (!$folderId) {
                $folderId = $this->createFolder($folderName, $currentParentId);
            }

            $currentParentId = $folderId;
        }

        return $currentParentId;
    }

    /**
     * Find folder in Google Drive.
     */
    protected function findFolder(string $name, ?string $parentId): ?string
    {
        $query = "name = '" . str_replace("'", "\\'", $name) . "' and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
        if ($parentId) {
            $query .= " and '{$parentId}' in parents";
        } else {
            $query .= " and 'root' in parents";
        }

        try {
            $response = $this->service->files->listFiles([
                'q' => $query,
                'spaces' => 'drive',
                'fields' => 'files(id, name)',
                'pageSize' => 1,
            ]);

            $files = $response->getFiles();
            return count($files) > 0 ? $files[0]->getId() : null;
        } catch (Exception $e) {
            Log::error("Failed finding folder '{$name}': " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create folder in Google Drive.
     */
    protected function createFolder(string $name, ?string $parentId): string
    {
        try {
            $fileMetadata = new DriveFile([
                'name' => $name,
                'mimeType' => 'application/vnd.google-apps.folder',
            ]);

            if ($parentId) {
                $fileMetadata->setParents([$parentId]);
            }

            $folder = $this->service->files->create($fileMetadata, [
                'fields' => 'id'
            ]);

            return $folder->getId();
        } catch (Exception $e) {
            Log::error("Failed creating folder '{$name}': " . $e->getMessage());
            throw new Exception("Could not create folder '{$name}' in Google Drive: " . $e->getMessage());
        }
    }

    /**
     * Find file in Google Drive under a specific folder.
     */
    protected function findFileInFolder(string $name, ?string $parentId): ?string
    {
        $query = "name = '" . str_replace("'", "\\'", $name) . "' and mimeType != 'application/vnd.google-apps.folder' and trashed = false";
        if ($parentId) {
            $query .= " and '{$parentId}' in parents";
        } else {
            $query .= " and 'root' in parents";
        }

        try {
            $response = $this->service->files->listFiles([
                'q' => $query,
                'spaces' => 'drive',
                'fields' => 'files(id, name)',
                'pageSize' => 1,
            ]);

            $files = $response->getFiles();
            return count($files) > 0 ? $files[0]->getId() : null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Upload an image to Google Drive.
     *
     * @param string $localFilePath Path to the local file
     * @param string $targetPath Target path in Google Drive (e.g. MKAS LARAVEL STORAGE/TRANSACTIONS/image.jpg)
     * @param array $options Options (async, compress, quality)
     * @return array
     */
    public function uploadImage(string $localFilePath, string $targetPath, array $options = []): array
    {
        $async = $options['async'] ?? (config('gdrive.upload_mode', 'sync') === 'async');
        $compress = $options['compress'] ?? config('gdrive.compress.enabled', true);
        $quality = $options['quality'] ?? config('gdrive.compress.quality', 75);

        if ($async) {
            // For async, we copy the file to a temporary storage path so the queue worker can access it.
            $tempDir = 'gdrive_temp';
            if (!Storage::exists($tempDir)) {
                Storage::makeDirectory($tempDir);
            }
            
            $tempFilename = $tempDir . '/' . uniqid() . '_' . basename($targetPath);
            Storage::put($tempFilename, file_get_contents($localFilePath));

            // Dispatch job
            \App\Jobs\UploadToGoogleDriveJob::dispatch($tempFilename, $targetPath, $compress, $quality);

            return [
                'status' => 'queued',
                'message' => 'Upload is being processed asynchronously.',
                'target_path' => $targetPath,
            ];
        }

        // Sync execution
        return $this->uploadSync($localFilePath, $targetPath, $compress, $quality);
    }

    /**
     * Synchronous upload logic.
     */
    public function uploadSync(string $localFilePath, string $targetPath, bool $compress, int $quality): array
    {
        $tempFile = null;
        $uploadFilePath = $localFilePath;

        // Perform compression if requested and it is an image
        if ($compress && $this->isImage($localFilePath)) {
            $tempFile = tempnam(sys_get_temp_dir(), 'gdrive_img_');
            if ($this->compressImage($localFilePath, $tempFile, $quality)) {
                $uploadFilePath = $tempFile;
            } else {
                // If compression fails, upload the original
                if ($tempFile && file_exists($tempFile)) {
                    unlink($tempFile);
                }
                $tempFile = null;
            }
        }

        try {
            $parentId = $this->resolveFolderIdForPath($targetPath);
            $filename = basename($targetPath);

            $fileMetadata = new DriveFile([
                'name' => $filename,
            ]);

            if ($parentId) {
                $fileMetadata->setParents([$parentId]);
            }

            $content = file_get_contents($uploadFilePath);
            $mimeType = mime_content_type($uploadFilePath) ?: 'application/octet-stream';
            $fileMetadata->setMimeType($mimeType);

            // Check if file already exists in that parent folder to update it (avoid duplicates)
            $existingFileId = $this->findFileInFolder($filename, $parentId);

            if ($existingFileId) {
                // Update file
                $file = $this->service->files->update($existingFileId, $fileMetadata, [
                    'data' => $content,
                    'mimeType' => $mimeType,
                    'uploadType' => 'multipart',
                    'fields' => 'id, name, webViewLink, webContentLink'
                ]);
            } else {
                // Create file
                $file = $this->service->files->create($fileMetadata, [
                    'data' => $content,
                    'mimeType' => $mimeType,
                    'uploadType' => 'multipart',
                    'fields' => 'id, name, webViewLink, webContentLink'
                ]);
            }

            // Cleanup temp file
            if ($tempFile && file_exists($tempFile)) {
                unlink($tempFile);
            }

            return [
                'status' => 'success',
                'id' => $file->getId(),
                'name' => $file->getName(),
                'webViewLink' => $file->getWebViewLink(),
                'webContentLink' => $file->getWebContentLink(),
                'target_path' => $targetPath,
            ];
        } catch (Exception $e) {
            if ($tempFile && file_exists($tempFile)) {
                unlink($tempFile);
            }
            Log::error("Google Drive Sync Upload failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Retrieve an image by its target path in Google Drive.
     *
     * @param string $targetPath
     * @return array
     */
    public function getImage(string $targetPath): array
    {
        $parentId = $this->resolveFolderIdForPath($targetPath);
        $filename = basename($targetPath);
        $fileId = $this->findFileInFolder($filename, $parentId);

        if (!$fileId) {
            throw new Exception("File not found in Google Drive: {$targetPath}");
        }

        try {
            $metadata = $this->service->files->get($fileId, ['fields' => 'mimeType, size']);
            $response = $this->service->files->get($fileId, ['alt' => 'media']);
            
            return [
                'content' => $response->getBody()->getContents(),
                'mimeType' => $metadata->getMimeType(),
                'size' => $metadata->getSize(),
            ];
        } catch (Exception $e) {
            Log::error("Failed to retrieve file from Google Drive: " . $e->getMessage());
            throw new Exception("Google Drive download error: " . $e->getMessage());
        }
    }

    /**
     * List files in a target path directory.
     */
    public function listFiles(string $targetPath = ''): array
    {
        try {
            $parentId = $this->resolveFolderIdForPath($targetPath . '/dummy.ext');
            
            $query = "mimeType != 'application/vnd.google-apps.folder' and trashed = false";
            if ($parentId) {
                $query .= " and '{$parentId}' in parents";
            } else {
                $query .= " and 'root' in parents";
            }

            $response = $this->service->files->listFiles([
                'q' => $query,
                'spaces' => 'drive',
                'fields' => 'files(id, name, mimeType, size, webViewLink, webContentLink, createdTime)',
                'pageSize' => 50,
            ]);

            $filesList = [];
            foreach ($response->getFiles() as $file) {
                $filesList[] = [
                    'id' => $file->getId(),
                    'name' => $file->getName(),
                    'mimeType' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'webViewLink' => $file->getWebViewLink(),
                    'webContentLink' => $file->getWebContentLink(),
                    'createdTime' => $file->getCreatedTime(),
                ];
            }

            return $filesList;
        } catch (Exception $e) {
            Log::error("Failed to list files from Google Drive: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Helper to detect if file path is an image.
     */
    protected function isImage(string $filePath): bool
    {
        $mime = mime_content_type($filePath);
        return $mime && str_starts_with($mime, 'image/');
    }

    /**
     * Native compression using PHP GD library.
     */
    protected function compressImage(string $sourcePath, string $destPath, int $quality): bool
    {
        try {
            $info = getimagesize($sourcePath);
            if (!$info) {
                return false;
            }

            $mime = $info['mime'];
            switch ($mime) {
                case 'image/jpeg':
                case 'image/jpg':
                    $image = imagecreatefromjpeg($sourcePath);
                    if ($image) {
                        imagejpeg($image, $destPath, $quality);
                        imagedestroy($image);
                        return true;
                    }
                    break;
                case 'image/png':
                    $image = imagecreatefrompng($sourcePath);
                    if ($image) {
                        // PNG is lossless, compression is 0 (no compression) to 9.
                        $pngQuality = max(0, min(9, (int)((100 - $quality) / 10)));
                        imagepng($image, $destPath, $pngQuality);
                        imagedestroy($image);
                        return true;
                    }
                    break;
                case 'image/webp':
                    $image = imagecreatefromwebp($sourcePath);
                    if ($image) {
                        imagewebp($image, $destPath, $quality);
                        imagedestroy($image);
                        return true;
                    }
                    break;
            }
        } catch (Exception $e) {
            Log::error("Image compression failed: " . $e->getMessage());
        }
        return false;
    }
}
