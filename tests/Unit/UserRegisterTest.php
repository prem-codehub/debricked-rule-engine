<?php

namespace Tests\Unit\Services;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use Tests\TestCase;

class UserRegisterTest extends TestCase
{
   
    use RefreshDatabase;


    /**
     * Test if the register endpoint is working without affecting existing users.
     */
    public function test_register_api_works(): void
    {
        // Count existing users before the test
        $existingUserCount = User::count();

        // Generate a unique email for testing
        $testEmail = 'test_'.time().'@example.com';

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => $testEmail,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        // Assert successful registration
        $response->assertStatus(201);

        // Ensure that only one new user is created
        $this->assertEquals($existingUserCount + 1, User::count());

        // Delete only the test-created user
        User::where('email', $testEmail)->delete();

        // Verify the test user is removed, and existing users remain
        $this->assertEquals($existingUserCount, User::count());
    }
}
