<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyEngineCallbackSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.engine.secret', '');
        if ($secret === '' && app()->environment('testing')) {
            $secret = 'test-engine-secret';
        }

        if ($secret === '') {
            return $this->error('Engine callback secret is not configured.', 500);
        }

        $timestamp = (string) $request->header('X-LinkFlow-Timestamp', '');
        $signature = (string) $request->header('X-LinkFlow-Signature', '');

        if ($timestamp === '' || $signature === '') {
            return $this->error('Missing callback signature headers.', 401);
        }

        $parsedTimestamp = strtotime($timestamp);
        if ($parsedTimestamp === false) {
            return $this->error('Invalid callback timestamp.', 401);
        }

        $ttl = max(1, (int) config('services.engine.callback_ttl', 300));
        if (abs(now()->timestamp - $parsedTimestamp) > $ttl) {
            return $this->error('Callback timestamp expired.', 401);
        }

        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        if (! hash_equals($expectedSignature, $signature)) {
            return $this->error('Invalid callback signature.', 401);
        }

        return $next($request);
    }

    protected function error(string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $message], $status);
    }
}
