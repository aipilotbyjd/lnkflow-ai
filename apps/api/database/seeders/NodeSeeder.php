<?php

namespace Database\Seeders;

use App\Enums\NodeKind;
use App\Models\Node;
use App\Models\NodeCategory;
use Illuminate\Database\Seeder;

class NodeSeeder extends Seeder
{
    public function run(): void
    {
        $categories = NodeCategory::query()->pluck('id', 'slug');

        $nodes = [
            // ─────────────────────────────────────────────────────────────
            // TRIGGERS
            // ─────────────────────────────────────────────────────────────
            [
                'category' => 'triggers',
                'type' => 'trigger_manual',
                'name' => 'Manual Trigger',
                'description' => 'Start workflow manually',
                'icon' => 'play',
                'color' => '#f59e0b',
                'node_kind' => NodeKind::Trigger,
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'input_schema' => [
                            'type' => 'object',
                            'title' => 'Input Schema',
                            'description' => 'Define expected input data',
                        ],
                    ],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'input' => ['type' => 'object'],
                        'triggered_at' => ['type' => 'string', 'format' => 'date-time'],
                        'triggered_by' => ['type' => 'object'],
                    ],
                ],
            ],
            [
                'category' => 'triggers',
                'type' => 'trigger_webhook',
                'name' => 'Webhook',
                'description' => 'Receive HTTP webhook calls',
                'icon' => 'link',
                'color' => '#f59e0b',
                'node_kind' => NodeKind::Trigger,
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'method' => [
                            'type' => 'array',
                            'items' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'DELETE']],
                            'default' => ['POST'],
                        ],
                        'path' => [
                            'type' => 'string',
                            'title' => 'Custom Path',
                            'description' => 'Custom webhook path',
                        ],
                        'response_status' => [
                            'type' => 'integer',
                            'default' => 200,
                        ],
                    ],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'headers' => ['type' => 'object'],
                        'query' => ['type' => 'object'],
                        'body' => ['type' => 'object'],
                        'method' => ['type' => 'string'],
                        'ip' => ['type' => 'string'],
                    ],
                ],
            ],
            [
                'category' => 'triggers',
                'type' => 'trigger_schedule',
                'name' => 'Schedule',
                'description' => 'Run on a schedule (cron)',
                'icon' => 'clock',
                'color' => '#f59e0b',
                'node_kind' => NodeKind::Trigger,
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'cron' => [
                            'type' => 'string',
                            'title' => 'Cron Expression',
                            'description' => 'e.g., "0 9 * * 1-5" for weekdays at 9am',
                        ],
                        'timezone' => [
                            'type' => 'string',
                            'default' => 'UTC',
                        ],
                    ],
                    'required' => ['cron'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'scheduled_time' => ['type' => 'string', 'format' => 'date-time'],
                        'execution_time' => ['type' => 'string', 'format' => 'date-time'],
                    ],
                ],
            ],

            // ─────────────────────────────────────────────────────────────
            // HTTP & APIs
            // ─────────────────────────────────────────────────────────────
            [
                'category' => 'http',
                'type' => 'action_http_request',
                'name' => 'HTTP Request',
                'description' => 'Make HTTP requests to any URL',
                'icon' => 'globe-alt',
                'color' => '#3b82f6',
                'node_kind' => NodeKind::Action,
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => ['type' => 'string', 'format' => 'uri', 'title' => 'URL'],
                        'method' => [
                            'type' => 'string',
                            'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'],
                            'default' => 'GET',
                        ],
                        'headers' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                        'query_params' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                        'body_type' => [
                            'type' => 'string',
                            'enum' => ['none', 'json', 'form', 'raw'],
                            'default' => 'none',
                        ],
                        'body' => ['type' => ['object', 'string', 'null']],
                        'timeout' => ['type' => 'integer', 'default' => 30, 'minimum' => 1, 'maximum' => 300],
                    ],
                    'required' => ['url', 'method'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'integer'],
                        'headers' => ['type' => 'object'],
                        'body' => ['type' => ['object', 'string', 'array']],
                        'duration_ms' => ['type' => 'integer'],
                    ],
                ],
            ],

            // ─────────────────────────────────────────────────────────────
            // COMMUNICATION
            // ─────────────────────────────────────────────────────────────
            [
                'category' => 'communication',
                'type' => 'action_send_email',
                'name' => 'Send Email',
                'description' => 'Send an email via SMTP',
                'icon' => 'envelope',
                'color' => '#8b5cf6',
                'node_kind' => NodeKind::Action,
                'credential_type' => 'smtp',
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'to' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'email']],
                        'cc' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'email']],
                        'bcc' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'email']],
                        'subject' => ['type' => 'string'],
                        'body' => ['type' => 'string'],
                        'body_type' => ['type' => 'string', 'enum' => ['text', 'html'], 'default' => 'html'],
                    ],
                    'required' => ['to', 'subject', 'body'],
                ],
            ],
            [
                'category' => 'communication',
                'type' => 'action_slack_message',
                'name' => 'Slack Message',
                'description' => 'Send a Slack message',
                'icon' => 'chat-bubble-left',
                'color' => '#4a154b',
                'node_kind' => NodeKind::Action,
                'credential_type' => 'slack',
                'is_premium' => true,
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'channel' => ['type' => 'string', 'title' => 'Channel'],
                        'message' => ['type' => 'string', 'title' => 'Message'],
                        'thread_ts' => ['type' => 'string', 'title' => 'Thread Timestamp'],
                    ],
                    'required' => ['channel', 'message'],
                ],
            ],

            // ─────────────────────────────────────────────────────────────
            // LOGIC
            // ─────────────────────────────────────────────────────────────
            [
                'category' => 'logic',
                'type' => 'logic_condition',
                'name' => 'IF Condition',
                'description' => 'Branch based on conditions',
                'icon' => 'arrows-right-left',
                'color' => '#6366f1',
                'node_kind' => NodeKind::Logic,
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'conditions' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'field' => ['type' => 'string'],
                                    'operator' => [
                                        'type' => 'string',
                                        'enum' => ['equals', 'not_equals', 'contains', 'greater_than', 'less_than', 'is_empty', 'is_not_empty'],
                                    ],
                                    'value' => ['type' => ['string', 'number', 'boolean', 'null']],
                                ],
                            ],
                        ],
                        'combine' => ['type' => 'string', 'enum' => ['and', 'or'], 'default' => 'and'],
                    ],
                    'required' => ['conditions'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'result' => ['type' => 'boolean'],
                    ],
                ],
            ],
            [
                'category' => 'logic',
                'type' => 'action_approval',
                'name' => 'Approval',
                'description' => 'Pause for human approval before continuing',
                'icon' => 'hand-thumb-up',
                'color' => '#0ea5e9',
                'node_kind' => NodeKind::Logic,
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'title' => 'Approval Title'],
                        'description' => ['type' => 'string', 'title' => 'Approval Description'],
                        'payload' => ['type' => 'object', 'title' => 'Approval Payload'],
                    ],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'approval_id' => ['type' => 'integer'],
                        'decision' => ['type' => 'string', 'enum' => ['approved', 'rejected']],
                        'decision_payload' => ['type' => 'object'],
                    ],
                ],
            ],
            [
                'category' => 'logic',
                'type' => 'logic_delay',
                'name' => 'Delay',
                'description' => 'Wait before continuing',
                'icon' => 'clock',
                'color' => '#6366f1',
                'node_kind' => NodeKind::Logic,
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'duration' => ['type' => 'integer', 'title' => 'Duration', 'minimum' => 1],
                        'unit' => ['type' => 'string', 'enum' => ['seconds', 'minutes', 'hours', 'days'], 'default' => 'seconds'],
                    ],
                    'required' => ['duration', 'unit'],
                ],
            ],
            [
                'category' => 'logic',
                'type' => 'logic_loop',
                'name' => 'Loop',
                'description' => 'Iterate over array items',
                'icon' => 'arrow-path',
                'color' => '#6366f1',
                'node_kind' => NodeKind::Logic,
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'items' => ['type' => 'string', 'title' => 'Items', 'description' => 'Expression returning array'],
                        'batch_size' => ['type' => 'integer', 'default' => 1],
                    ],
                    'required' => ['items'],
                ],
            ],

            // ─────────────────────────────────────────────────────────────
            // DATA TRANSFORM
            // ─────────────────────────────────────────────────────────────
            [
                'category' => 'data',
                'type' => 'transform_set',
                'name' => 'Set Values',
                'description' => 'Set or modify data values',
                'icon' => 'pencil-square',
                'color' => '#10b981',
                'node_kind' => NodeKind::Transform,
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'values' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'key' => ['type' => 'string'],
                                    'value' => ['type' => ['string', 'number', 'boolean', 'object', 'array']],
                                ],
                            ],
                        ],
                    ],
                    'required' => ['values'],
                ],
            ],
            [
                'category' => 'data',
                'type' => 'transform_filter',
                'name' => 'Filter Array',
                'description' => 'Filter array items by condition',
                'icon' => 'funnel',
                'color' => '#10b981',
                'node_kind' => NodeKind::Transform,
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'input' => ['type' => 'string', 'title' => 'Input Array'],
                        'condition' => [
                            'type' => 'object',
                            'properties' => [
                                'field' => ['type' => 'string'],
                                'operator' => ['type' => 'string'],
                                'value' => ['type' => ['string', 'number', 'boolean']],
                            ],
                        ],
                    ],
                    'required' => ['input', 'condition'],
                ],
            ],
            [
                'category' => 'data',
                'type' => 'transform_code',
                'name' => 'Code (JavaScript)',
                'description' => 'Run custom JavaScript code',
                'icon' => 'code-bracket',
                'color' => '#f59e0b',
                'node_kind' => NodeKind::Transform,
                'is_premium' => true,
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'code' => ['type' => 'string', 'title' => 'JavaScript Code'],
                        'timeout' => ['type' => 'integer', 'default' => 10],
                    ],
                    'required' => ['code'],
                ],
            ],

            // ─────────────────────────────────────────────────────────────
            // INTEGRATIONS
            // ─────────────────────────────────────────────────────────────
            [
                'category' => 'integrations',
                'type' => 'integration_google_sheets',
                'name' => 'Google Sheets',
                'description' => 'Read/write Google Sheets',
                'icon' => 'table-cells',
                'color' => '#34a853',
                'node_kind' => NodeKind::Action,
                'credential_type' => 'google',
                'is_premium' => true,
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'operation' => ['type' => 'string', 'enum' => ['read', 'append', 'update', 'clear']],
                        'spreadsheet_id' => ['type' => 'string'],
                        'sheet_name' => ['type' => 'string'],
                        'range' => ['type' => 'string'],
                        'data' => ['type' => 'array'],
                    ],
                    'required' => ['operation', 'spreadsheet_id'],
                ],
            ],
            [
                'category' => 'integrations',
                'type' => 'integration_stripe',
                'name' => 'Stripe',
                'description' => 'Stripe payment operations',
                'icon' => 'credit-card',
                'color' => '#635bff',
                'node_kind' => NodeKind::Action,
                'credential_type' => 'stripe',
                'is_premium' => true,
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'resource' => ['type' => 'string', 'enum' => ['customer', 'charge', 'invoice', 'subscription']],
                        'operation' => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'delete']],
                        'data' => ['type' => 'object'],
                    ],
                    'required' => ['resource', 'operation'],
                ],
            ],
            [
                'category' => 'integrations',
                'type' => 'integration_github',
                'name' => 'GitHub',
                'description' => 'GitHub API operations',
                'icon' => 'code-bracket-square',
                'color' => '#181717',
                'node_kind' => NodeKind::Action,
                'credential_type' => 'github',
                'is_premium' => true,
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'resource' => ['type' => 'string', 'enum' => ['issue', 'pull_request', 'repository', 'release']],
                        'operation' => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'delete']],
                        'owner' => ['type' => 'string'],
                        'repo' => ['type' => 'string'],
                        'data' => ['type' => 'object'],
                    ],
                    'required' => ['resource', 'operation', 'owner', 'repo'],
                ],
            ],
        ];

        foreach ($nodes as $nodeData) {
            $categorySlug = $nodeData['category'];
            unset($nodeData['category']);

            $nodeData['category_id'] = $categories[$categorySlug];
            $nodeData['is_active'] = $nodeData['is_active'] ?? true;
            $nodeData['is_premium'] = $nodeData['is_premium'] ?? false;

            Node::query()->updateOrCreate(
                ['type' => $nodeData['type']],
                $nodeData
            );
        }
    }
}
