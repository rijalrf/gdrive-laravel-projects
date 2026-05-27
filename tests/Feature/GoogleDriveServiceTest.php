<?php

namespace Tests\Feature;

use App\Jobs\UploadToGoogleDriveJob;
use App\Services\GoogleDriveService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoogleDriveServiceTest extends TestCase
{
    /**
     * Test that uploading with async enabled dispatches the queue job correctly.
     */
    public function test_async_upload_dispatches_queue_job()
    {
        Queue::fake();
        Storage::fake('local');

        $file = UploadedFile::fake()->image('test_transaction.jpg', 600, 600);
        $tempPath = $file->getPathname();

        $service = new GoogleDriveService();
        $targetPath = 'MKAS LARAVEL STORAGE/TRANSACTIONS/test_transaction.jpg';

        $result = $service->uploadImage($tempPath, $targetPath, [
            'async' => true,
            'compress' => true,
            'quality' => 50
        ]);

        $this->assertEquals('queued', $result['status']);
        $this->assertEquals($targetPath, $result['target_path']);

        Queue::assertPushed(UploadToGoogleDriveJob::class, function ($job) use ($targetPath) {
            // Access properties via Reflection or directly since they are protected/public.
            // Since they are protected in our job, we can check via reflection or we can make them public.
            // Let's use reflection to read protected fields or check the dispatch occurred.
            $reflection = new \ReflectionClass(UploadToGoogleDriveJob::class);
            
            $targetPathProp = $reflection->getProperty('targetPath');
            $targetPathProp->setAccessible(true);
            
            $compressProp = $reflection->getProperty('compress');
            $compressProp->setAccessible(true);
            
            $qualityProp = $reflection->getProperty('quality');
            $qualityProp->setAccessible(true);

            return $targetPathProp->getValue($job) === $targetPath &&
                   $compressProp->getValue($job) === true &&
                   $qualityProp->getValue($job) === 50;
        });
    }

    /**
     * Test that the native GD image compression works and significantly reduces file size.
     */
    public function test_image_compression_utility()
    {
        // Create an uncompressed large fake image
        $image = imagecreatetruecolor(800, 800);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);
        
        // Add some random shapes to make the file size more responsive to compression
        $red = imagecolorallocate($image, 255, 0, 0);
        $blue = imagecolorallocate($image, 0, 0, 255);
        for ($i = 0; $i < 50; $i++) {
            imagefilledellipse($image, rand(10, 790), rand(10, 790), rand(20, 100), rand(20, 100), $red);
            imagerectangle($image, rand(10, 790), rand(10, 790), rand(10, 790), rand(10, 790), $blue);
        }
        
        $tempOriginal = tempnam(sys_get_temp_dir(), 'test_orig_');
        imagejpeg($image, $tempOriginal, 100); // 100% quality (uncompressed/large)
        imagedestroy($image);

        $tempCompressed = tempnam(sys_get_temp_dir(), 'test_comp_');

        // Access the protected compressImage method via reflection
        $service = new GoogleDriveService();
        $reflection = new \ReflectionClass(GoogleDriveService::class);
        $method = $reflection->getMethod('compressImage');
        $method->setAccessible(true);

        // Compress to 10% quality
        $success = $method->invokeArgs($service, [$tempOriginal, $tempCompressed, 10]);

        $this->assertTrue($success);
        $this->assertFileExists($tempCompressed);
        
        $originalSize = filesize($tempOriginal);
        $compressedSize = filesize($tempCompressed);

        // Compressed image should be significantly smaller than original
        $this->assertLessThan($originalSize, $compressedSize);

        // Cleanup
        unlink($tempOriginal);
        unlink($tempCompressed);
    }

    /**
     * Test that GoogleDriveService correctly initializes the Google Client in refresh_token mode.
     */
    public function test_initialization_with_refresh_token()
    {
        config(['gdrive.credentials.mode' => 'refresh_token']);
        config(['gdrive.credentials.client_id' => 'mock-client-id']);
        config(['gdrive.credentials.client_secret' => 'mock-client-secret']);
        config(['gdrive.credentials.refresh_token' => 'mock-refresh-token']);

        $mockClient = \Mockery::mock(\Google\Client::class);
        $mockClient->shouldReceive('setScopes')->once()->with([\Google\Service\Drive::DRIVE]);
        $mockClient->shouldReceive('setClientId')->once()->with('mock-client-id');
        $mockClient->shouldReceive('setClientSecret')->once()->with('mock-client-secret');
        $mockClient->shouldReceive('refreshToken')->once()->with('mock-refresh-token')->andReturn([]);

        $service = new GoogleDriveService($mockClient);
        
        $reflection = new \ReflectionClass(GoogleDriveService::class);
        $clientProp = $reflection->getProperty('client');
        $clientProp->setAccessible(true);
        
        $this->assertSame($mockClient, $clientProp->getValue($service));
    }

    /**
     * Test that GoogleDriveService logs a warning when refresh token credentials are incomplete.
     */
    public function test_initialization_with_incomplete_refresh_token_logs_warning()
    {
        config(['gdrive.credentials.mode' => 'refresh_token']);
        config(['gdrive.credentials.client_id' => null]);
        config(['gdrive.credentials.client_secret' => null]);
        config(['gdrive.credentials.refresh_token' => null]);

        \Illuminate\Support\Facades\Log::shouldReceive('warning')
            ->once()
            ->with("Google Drive OAuth credentials (client_id, client_secret, refresh_token) are missing or incomplete.");

        $mockClient = \Mockery::mock(\Google\Client::class);
        $mockClient->shouldReceive('setScopes')->once()->with([\Google\Service\Drive::DRIVE]);

        new GoogleDriveService($mockClient);
    }
}
