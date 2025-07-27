<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DependencyUploadTest extends TestCase
{
    use RefreshDatabase;

    private $testFiles = [];
    private $uploadedFiles = [];

    public function test_user_can_upload_multiple_dependency_files()
    {
        // Use real local disk and create directory
        Storage::disk('local')->makeDirectory('private/dependencies');

        $user = User::factory()->create([
            'email' => 'test_user_' . time() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        $token = auth()->login($user);

        // Create physical test files
        $file1Path = storage_path('composer.lock');
        $file2Path = storage_path('package.json');

        // Track files for cleanup
        $this->testFiles[] = $file1Path;
        $this->testFiles[] = $file2Path;

        file_put_contents($file1Path, 'composer test content');
        file_put_contents($file2Path, 'yarn test content');

        $file1 = new \Illuminate\Http\UploadedFile($file1Path, 'composer.lock', null, null, true);
        $file2 = new \Illuminate\Http\UploadedFile($file2Path, 'yarn.lock', null, null, true);

        $response = $this->postJson('/api/dependency-uploads', [
            'files' => [$file1, $file2],
            'repository_name' => 'default_repo',
            'commit_name' => 'test-commit-' . uniqid(),
        ], [
            'Authorization' => "Bearer $token",
        ]);

        $response->assertStatus(200);

        // Track uploaded files for cleanup (adjust paths based on your controller logic)
        $this->uploadedFiles[] = 'private/dependencies/composer.lock';
        $this->uploadedFiles[] = 'private/dependencies/yarn.lock';
    }

    protected function tearDown(): void
    {
        // Clean up test files created in storage_path()
        foreach ($this->testFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        // Clean up uploaded files in storage/app/
        foreach ($this->uploadedFiles as $file) {
            if (Storage::disk('local')->exists($file)) {
                Storage::disk('local')->delete($file);
            }
        }

        // Clean up directories if empty
        $directories = ['private/dependencies', 'private', 'dependencies'];
        foreach ($directories as $dir) {
            if (Storage::disk('local')->exists($dir)) {
                $files = Storage::disk('local')->files($dir);
                if (empty($files)) {
                    Storage::disk('local')->deleteDirectory($dir);
                }
            }
        }

        parent::tearDown();
    }
}