<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\ClientRepository;

uses(RefreshDatabase::class);

beforeEach(function () {
    $client = app(ClientRepository::class)->createPasswordGrantClient(
        'Test Password Grant Client',
        'users',
        true
    );

    config()->set('passport.password_client_id', $client->getKey());
    config()->set('passport.password_client_secret', $client->plainSecret);
});

describe('Authentication', function () {
    describe('Registration', function () {
        it('can register a new user', function () {
            $response = $this->postJson('/api/v1/auth/register', [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

            $response->assertStatus(201)
                ->assertJsonStructure([
                    'message',
                    'user' => ['id', 'first_name', 'last_name', 'email'],
                    'access_token',
                    'refresh_token',
                    'token_type',
                    'expires_in',
                ]);

            $this->assertDatabaseHas('users', [
                'email' => 'john@example.com',
            ]);
        });

        it('fails with invalid email', function () {
            $response = $this->postJson('/api/v1/auth/register', [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'invalid-email',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['email']);
        });

        it('fails with duplicate email', function () {
            User::factory()->create(['email' => 'john@example.com']);

            $response = $this->postJson('/api/v1/auth/register', [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['email']);
        });

        it('fails with weak password', function () {
            $response = $this->postJson('/api/v1/auth/register', [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john@example.com',
                'password' => '123',
                'password_confirmation' => '123',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['password']);
        });
    });

    describe('Login', function () {
        it('can login with valid credentials', function () {
            $user = User::factory()->create([
                'password' => bcrypt('password123'),
            ]);

            $response = $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'password123',
            ]);

            $response->assertStatus(200)
                ->assertJsonStructure([
                    'access_token',
                    'refresh_token',
                    'token_type',
                    'expires_in',
                    'user',
                ]);
        });

        it('fails with invalid credentials', function () {
            $user = User::factory()->create([
                'password' => bcrypt('password123'),
            ]);

            $response = $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'wrongpassword',
            ]);

            $response->assertStatus(401);
        });

        it('fails with non-existent user', function () {
            $response = $this->postJson('/api/v1/auth/login', [
                'email' => 'nonexistent@example.com',
                'password' => 'password123',
            ]);

            $response->assertStatus(401);
        });
    });

    describe('Token Refresh', function () {
        it('can refresh token with valid refresh token', function () {
            $user = User::factory()->create([
                'password' => bcrypt('password123'),
            ]);

            $loginResponse = $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'password123',
            ]);

            $refreshToken = $loginResponse->json('refresh_token');

            $response = $this->postJson('/api/v1/auth/refresh', [
                'refresh_token' => $refreshToken,
            ]);

            $response->assertStatus(200)
                ->assertJsonStructure([
                    'message',
                    'access_token',
                    'refresh_token',
                    'token_type',
                    'expires_in',
                ]);

            expect($response->json('access_token'))->not->toBe($loginResponse->json('access_token'));
        });

        it('fails with invalid refresh token', function () {
            $response = $this->postJson('/api/v1/auth/refresh', [
                'refresh_token' => 'invalid-refresh-token',
            ]);

            $response->assertStatus(401);
        });

        it('fails validation when refresh token is missing', function () {
            $response = $this->postJson('/api/v1/auth/refresh', []);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['refresh_token']);
        });
    });

    describe('Logout', function () {
        it('can logout authenticated user', function () {
            $user = User::factory()->create();

            $loginResponse = $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

            $response = $this->withHeader('Authorization', 'Bearer '.$loginResponse->json('access_token'))
                ->postJson('/api/v1/auth/logout');

            $response->assertStatus(200)
                ->assertJson(['message' => 'Logged out successfully.']);

            $this->assertDatabaseHas('oauth_access_tokens', [
                'user_id' => $user->id,
                'revoked' => true,
            ]);

            $this->assertDatabaseHas('oauth_refresh_tokens', [
                'revoked' => true,
            ]);
        });

        it('requires authentication to logout', function () {
            $response = $this->postJson('/api/v1/auth/logout');

            $response->assertStatus(401);
        });
    });

    describe('Password Reset', function () {
        it('can request password reset', function () {
            $user = User::factory()->create();

            $response = $this->postJson('/api/v1/auth/forgot-password', [
                'email' => $user->email,
            ]);

            $response->assertStatus(200)
                ->assertJsonStructure(['message']);
        });

        it('fails for non-existent emails', function () {
            $response = $this->postJson('/api/v1/auth/forgot-password', [
                'email' => 'nonexistent@example.com',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['email']);
        });
    });
});

describe('User Profile', function () {
    it('can get current user profile', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'api')
            ->getJson('/api/v1/user');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'user' => ['id', 'first_name', 'last_name', 'email'],
            ]);
    });

    it('can update user profile', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'api')
            ->putJson('/api/v1/user', [
                'first_name' => 'Updated',
                'last_name' => 'Name',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'first_name' => 'Updated',
            'last_name' => 'Name',
        ]);
    });

    it('can change password', function () {
        $user = User::factory()->create([
            'password' => bcrypt('oldpassword'),
        ]);

        $response = $this->actingAs($user, 'api')
            ->putJson('/api/v1/user/password', [
                'current_password' => 'oldpassword',
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ]);

        $response->assertStatus(200);
    });

    it('fails to change password with wrong current password', function () {
        $user = User::factory()->create([
            'password' => bcrypt('oldpassword'),
        ]);

        $response = $this->actingAs($user, 'api')
            ->putJson('/api/v1/user/password', [
                'current_password' => 'wrongpassword',
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ]);

        $response->assertStatus(422);
    });

    it('requires authentication for profile access', function () {
        $response = $this->getJson('/api/v1/user');

        $response->assertStatus(401);
    });
});
