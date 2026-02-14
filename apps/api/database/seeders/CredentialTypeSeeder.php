<?php

namespace Database\Seeders;

use App\Models\CredentialType;
use Illuminate\Database\Seeder;

class CredentialTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'type' => 'api_key',
                'name' => 'API Key',
                'description' => 'Generic API key authentication',
                'icon' => 'key',
                'color' => '#6366f1',
                'fields_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'api_key' => ['type' => 'string', 'title' => 'API Key', 'secret' => true],
                        'header_name' => ['type' => 'string', 'title' => 'Header Name', 'default' => 'X-API-Key'],
                    ],
                    'required' => ['api_key'],
                ],
            ],
            [
                'type' => 'bearer_token',
                'name' => 'Bearer Token',
                'description' => 'Bearer token authentication',
                'icon' => 'shield-check',
                'color' => '#8b5cf6',
                'fields_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'token' => ['type' => 'string', 'title' => 'Token', 'secret' => true],
                    ],
                    'required' => ['token'],
                ],
            ],
            [
                'type' => 'basic_auth',
                'name' => 'Basic Auth',
                'description' => 'HTTP Basic authentication',
                'icon' => 'user',
                'color' => '#10b981',
                'fields_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'username' => ['type' => 'string', 'title' => 'Username'],
                        'password' => ['type' => 'string', 'title' => 'Password', 'secret' => true],
                    ],
                    'required' => ['username', 'password'],
                ],
            ],
            [
                'type' => 'smtp',
                'name' => 'SMTP',
                'description' => 'SMTP email server credentials',
                'icon' => 'envelope',
                'color' => '#ec4899',
                'fields_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'host' => ['type' => 'string', 'title' => 'SMTP Host'],
                        'port' => ['type' => 'integer', 'title' => 'Port', 'default' => 587],
                        'username' => ['type' => 'string', 'title' => 'Username'],
                        'password' => ['type' => 'string', 'title' => 'Password', 'secret' => true],
                        'encryption' => ['type' => 'string', 'enum' => ['tls', 'ssl', 'none'], 'default' => 'tls'],
                        'from_email' => ['type' => 'string', 'title' => 'From Email'],
                        'from_name' => ['type' => 'string', 'title' => 'From Name'],
                    ],
                    'required' => ['host', 'port', 'username', 'password'],
                ],
            ],
            [
                'type' => 'slack',
                'name' => 'Slack',
                'description' => 'Slack Bot credentials',
                'icon' => 'chat-bubble-left',
                'color' => '#4a154b',
                'fields_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'bot_token' => ['type' => 'string', 'title' => 'Bot Token', 'description' => 'Starts with xoxb-', 'secret' => true],
                    ],
                    'required' => ['bot_token'],
                ],
                'test_config' => [
                    'method' => 'http',
                    'url' => 'https://slack.com/api/auth.test',
                    'headers' => ['Authorization' => 'Bearer {{bot_token}}'],
                ],
                'docs_url' => 'https://api.slack.com/authentication/token-types',
            ],
            [
                'type' => 'stripe',
                'name' => 'Stripe',
                'description' => 'Stripe API credentials',
                'icon' => 'credit-card',
                'color' => '#635bff',
                'fields_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'secret_key' => ['type' => 'string', 'title' => 'Secret Key', 'description' => 'Starts with sk_', 'secret' => true],
                        'publishable_key' => ['type' => 'string', 'title' => 'Publishable Key', 'description' => 'Starts with pk_'],
                    ],
                    'required' => ['secret_key'],
                ],
                'test_config' => [
                    'method' => 'http',
                    'url' => 'https://api.stripe.com/v1/balance',
                    'headers' => ['Authorization' => 'Bearer {{secret_key}}'],
                ],
            ],
            [
                'type' => 'github',
                'name' => 'GitHub',
                'description' => 'GitHub Personal Access Token',
                'icon' => 'code-bracket-square',
                'color' => '#181717',
                'fields_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'token' => ['type' => 'string', 'title' => 'Personal Access Token', 'secret' => true],
                    ],
                    'required' => ['token'],
                ],
                'test_config' => [
                    'method' => 'http',
                    'url' => 'https://api.github.com/user',
                    'headers' => ['Authorization' => 'token {{token}}'],
                ],
            ],
            [
                'type' => 'google',
                'name' => 'Google',
                'description' => 'Google OAuth2 credentials',
                'icon' => 'globe-alt',
                'color' => '#4285f4',
                'fields_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'string', 'title' => 'Client ID'],
                        'client_secret' => ['type' => 'string', 'title' => 'Client Secret', 'secret' => true],
                        'refresh_token' => ['type' => 'string', 'title' => 'Refresh Token', 'secret' => true],
                    ],
                    'required' => ['client_id', 'client_secret', 'refresh_token'],
                ],
            ],
            [
                'type' => 'twilio',
                'name' => 'Twilio',
                'description' => 'Twilio SMS credentials',
                'icon' => 'phone',
                'color' => '#f22f46',
                'fields_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'account_sid' => ['type' => 'string', 'title' => 'Account SID'],
                        'auth_token' => ['type' => 'string', 'title' => 'Auth Token', 'secret' => true],
                        'from_number' => ['type' => 'string', 'title' => 'From Phone Number'],
                    ],
                    'required' => ['account_sid', 'auth_token', 'from_number'],
                ],
            ],
            [
                'type' => 'database_mysql',
                'name' => 'MySQL',
                'description' => 'MySQL database connection',
                'icon' => 'circle-stack',
                'color' => '#00758f',
                'fields_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'host' => ['type' => 'string', 'title' => 'Host', 'default' => 'localhost'],
                        'port' => ['type' => 'integer', 'title' => 'Port', 'default' => 3306],
                        'database' => ['type' => 'string', 'title' => 'Database Name'],
                        'username' => ['type' => 'string', 'title' => 'Username'],
                        'password' => ['type' => 'string', 'title' => 'Password', 'secret' => true],
                    ],
                    'required' => ['host', 'database', 'username', 'password'],
                ],
            ],
            [
                'type' => 'database_postgres',
                'name' => 'PostgreSQL',
                'description' => 'PostgreSQL database connection',
                'icon' => 'circle-stack',
                'color' => '#336791',
                'fields_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'host' => ['type' => 'string', 'title' => 'Host', 'default' => 'localhost'],
                        'port' => ['type' => 'integer', 'title' => 'Port', 'default' => 5432],
                        'database' => ['type' => 'string', 'title' => 'Database Name'],
                        'username' => ['type' => 'string', 'title' => 'Username'],
                        'password' => ['type' => 'string', 'title' => 'Password', 'secret' => true],
                        'ssl_mode' => ['type' => 'string', 'enum' => ['disable', 'require', 'verify-full']],
                    ],
                    'required' => ['host', 'database', 'username', 'password'],
                ],
            ],
        ];

        foreach ($types as $type) {
            CredentialType::query()->updateOrCreate(
                ['type' => $type['type']],
                $type
            );
        }
    }
}
