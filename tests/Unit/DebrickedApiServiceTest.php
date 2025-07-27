<?php

namespace Tests\Unit\Services;

use App\Models\DependencyFile;
use App\Models\DependencyUpload;
use App\Services\DebrickedApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DebrickedApiServiceTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();

        config(['services.debricked_api.username' => 'testuser']);
        config(['services.debricked_api.password' => 'testpass']);
    }

    public function test_authentication_success()
    {
        Http::fake([
            'https://debricked.com/api/login_check' => Http::response(['token' => 'fake-token'], 200),
        ]);

        $service = new DebrickedApiService();

        $this->assertTrue($service->isAuthenticated());
        $this->assertEquals('fake-token', $service->getToken());
    }

    public function test_get_supported_file_formats()
    {
        Http::fake([
            'https://debricked.com/api/login_check' => Http::response(['token' => 'fake-token'], 200),
            'https://debricked.com/api/1.0/open/files/supported-formats' => Http::response([
                ['regex' => '\\package.json'],
            ], 200),
        ]);

        $service = new DebrickedApiService();

        $formats = $service->getSupportedFileFormats();

        $this->assertIsArray($formats);
        $this->assertEquals('\\package.json', $formats[0]['regex']);
    }


    public function test_upload_dependency_file_and_queue_scan()
    {
        // Fake the storage
        Storage::fake();

        // Create a fake file in storage
        $filePath = 'dependencies/' . uniqid() . '_yarn.lock';
        Storage::put($filePath, 'test content');


        $user = \App\Models\User::factory()->create();

        $dependencyUpload = DependencyUpload::create([
            'user_id' => $user->id,
            'commit_name' => 'default_commit',
            'repository_name' => 'default_repo',
            'status' => 'pending',
        ]);

        $attachment = DependencyFile::create([
            'dependency_upload_id' => $dependencyUpload->id,
            'filename' => 'yarn.lock',
            'path' => $filePath,
            'vulnerabilities_found' => 0,
            'progress' => 0,
            'raw_data' => [],
        ]);


        Http::fake([
            'https://debricked.com/api/login_check' => Http::response(['token' => 'fake-token'], 200),
            'https://debricked.com/api/1.0/open/uploads/dependencies/files' => Http::response([
                'ciUploadId' => 7986946,
            ], 200),
            'https://debricked.com/api/1.0/open/finishes/dependencies/files/uploads' => Http::response([], 200),
        ]);

        $service = new DebrickedApiService();

        $response = $service->uploadDependencyFile($attachment, 'default-commit', 'default-repo');

        $this->assertEquals(7986946, $response['ciUploadId']);
        $this->assertDatabaseHas('dependency_files', [
            'id' => $attachment->id,
            'ci_upload_id' => 7986946,
        ]);
    }

    public function test_get_upload_status()
    {
        Http::fake([
            'https://debricked.com/api/login_check' => Http::response(['token' => 'fake-token'], 200),
            'https://debricked.com/api/1.0/open/ci/upload/status*' => Http::response([
                'progress' => 100,
                'vulnerabilitiesFound' => 5,
            ], 200),
        ]);

        $service = new DebrickedApiService();

        $response = $service->getUploadStatus('1234');

        $this->assertTrue($response->successful());
        $this->assertEquals(100, $response->json()['progress']);
    }
}
