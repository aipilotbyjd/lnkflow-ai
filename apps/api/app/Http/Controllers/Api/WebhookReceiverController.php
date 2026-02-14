<?php

namespace App\Http\Controllers\Api;

use App\Enums\WebhookAuthType;
use App\Http\Controllers\Controller;
use App\Models\Webhook;
use App\Services\WorkflowDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class WebhookReceiverController extends Controller
{
    public function __construct(
        private WorkflowDispatchService $dispatchService
    ) {}

    public function handle(Request $request, string $uuid, ?string $path = null): JsonResponse
    {
        $webhook = Webhook::query()
            ->where('uuid', $uuid)
            ->where('is_active', true)
            ->with('workflow')
            ->first();

        if (! $webhook) {
            return response()->json(['error' => 'Webhook not found'], 404);
        }

        if ($webhook->path && $webhook->path !== $path) {
            return response()->json(['error' => 'Invalid webhook path'], 404);
        }

        if (! $webhook->path && $path !== null) {
            return response()->json(['error' => 'Invalid webhook path'], 404);
        }

        if (! $webhook->isMethodAllowed($request->method())) {
            return response()->json(['error' => 'Method not allowed'], 405);
        }

        if (! $this->validateAuth($request, $webhook)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (! $this->checkRateLimit($webhook, $request)) {
            return response()->json(['error' => 'Rate limit exceeded'], 429);
        }

        $triggerData = [
            'method' => $request->method(),
            'headers' => $request->headers->all(),
            'query' => $request->query(),
            'body' => $request->all(),
            'ip' => $request->ip(),
            'path' => $path,
        ];

        try {
            $result = $this->dispatchService->dispatchHighPriority(
                workflow: $webhook->workflow,
                mode: 'webhook',
                triggerData: $triggerData,
            );

            $webhook->incrementCallCount();

            return response()->json(
                $webhook->response_body ?? ['success' => true, 'execution_id' => $result['execution']->id],
                $webhook->response_status
            );
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    private function validateAuth(Request $request, Webhook $webhook): bool
    {
        if ($webhook->auth_type === WebhookAuthType::None) {
            return true;
        }

        $authConfig = $webhook->getDecryptedAuthConfig();

        if (! $authConfig) {
            return true;
        }

        return match ($webhook->auth_type) {
            WebhookAuthType::Header => $this->validateHeaderAuth($request, $authConfig),
            WebhookAuthType::Basic => $this->validateBasicAuth($request, $authConfig),
            WebhookAuthType::Bearer => $this->validateBearerAuth($request, $authConfig),
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateHeaderAuth(Request $request, array $config): bool
    {
        $headerName = $config['header_name'] ?? 'X-Webhook-Secret';
        $expectedValue = $config['header_value'] ?? '';

        return $request->header($headerName) === $expectedValue;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateBasicAuth(Request $request, array $config): bool
    {
        $expectedUsername = $config['username'] ?? '';
        $expectedPassword = $config['password'] ?? '';

        return $request->getUser() === $expectedUsername
            && $request->getPassword() === $expectedPassword;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateBearerAuth(Request $request, array $config): bool
    {
        $expectedToken = $config['token'] ?? '';

        return $request->bearerToken() === $expectedToken;
    }

    private function checkRateLimit(Webhook $webhook, Request $request): bool
    {
        if (! $webhook->rate_limit) {
            return true;
        }

        $key = 'webhook:'.$webhook->id.':'.$request->ip();

        return RateLimiter::attempt(
            $key,
            $webhook->rate_limit,
            fn () => true,
            60
        );
    }
}
