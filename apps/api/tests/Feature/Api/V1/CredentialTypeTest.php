<?php

use App\Models\User;
use Database\Seeders\CredentialTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(CredentialTypeSeeder::class);

    $this->user = User::factory()->create();
    Passport::actingAs($this->user);
});

describe('index', function () {
    it('returns all credential types', function () {
        $response = $this->getJson('/api/v1/credential-types');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'type',
                        'name',
                        'description',
                        'icon',
                        'color',
                        'fields_schema',
                    ],
                ],
            ]);

        expect($response->json('data'))->not->toBeEmpty();
    });

    it('includes common credential types', function () {
        $response = $this->getJson('/api/v1/credential-types');

        $types = collect($response->json('data'))->pluck('type')->all();

        expect($types)->toContain('api_key');
        expect($types)->toContain('bearer_token');
        expect($types)->toContain('basic_auth');
        expect($types)->toContain('slack');
        expect($types)->toContain('github');
    });
});

describe('show', function () {
    it('returns a single credential type', function () {
        $response = $this->getJson('/api/v1/credential-types/slack');

        $response->assertSuccessful()
            ->assertJsonPath('credential_type.type', 'slack')
            ->assertJsonPath('credential_type.name', 'Slack');
    });

    it('includes fields schema', function () {
        $response = $this->getJson('/api/v1/credential-types/slack');

        $response->assertSuccessful();

        $fieldsSchema = $response->json('credential_type.fields_schema');
        expect($fieldsSchema)->toHaveKey('properties');
        expect($fieldsSchema['properties'])->toHaveKey('bot_token');
    });

    it('returns not found for invalid type', function () {
        $response = $this->getJson('/api/v1/credential-types/non_existent');

        $response->assertNotFound();
    });
});
