<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Execution;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExecutionStreamController extends Controller
{
    public function stream(Request $request, Workspace $workspace, Execution $execution): StreamedResponse
    {
        $this->authorize('execution.view');

        if ($execution->workspace_id !== $workspace->id) {
            abort(404, 'Execution not found.');
        }

        $channelKey = "execution:{$execution->id}:events";
        $connectionTimeout = 1800; // 30 minutes max
        $heartbeatInterval = 15; // seconds

        return new StreamedResponse(function () use ($channelKey, $connectionTimeout, $heartbeatInterval, $execution) {
            if (ob_get_level()) {
                ob_end_clean();
            }

            $startTime = time();
            $lastHeartbeat = time();
            $lastEventId = '0-0';

            $this->sendSseEvent('connected', [
                'execution_id' => $execution->id,
                'status' => $execution->status->value,
                'connected_at' => now()->toIso8601String(),
            ]);

            while (true) {
                if ((time() - $startTime) >= $connectionTimeout) {
                    $this->sendSseEvent('timeout', [
                        'message' => 'Connection timed out after ' . ($connectionTimeout / 60) . ' minutes.',
                    ]);
                    break;
                }

                if (connection_aborted()) {
                    break;
                }

                try {
                    $messages = Redis::xread([$channelKey => $lastEventId], 10, 2000);
                } catch (\Throwable) {
                    $messages = null;
                }

                if ($messages && isset($messages[$channelKey])) {
                    foreach ($messages[$channelKey] as $messageId => $messageData) {
                        $lastEventId = $messageId;

                        $payload = json_decode($messageData['payload'] ?? '{}', true);
                        $eventType = $payload['event'] ?? 'unknown';
                        unset($payload['event']);

                        $this->sendSseEvent($eventType, $payload, $messageId);

                        if (in_array($eventType, ['execution.completed', 'execution.failed'], true)) {
                            return;
                        }
                    }
                }

                if ((time() - $lastHeartbeat) >= $heartbeatInterval) {
                    $this->sendSseEvent('heartbeat', [
                        'timestamp' => now()->toIso8601String(),
                    ]);
                    $lastHeartbeat = time();
                }

                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sendSseEvent(string $event, array $data, ?string $id = null): void
    {
        if ($id !== null) {
            echo "id: {$id}\n";
        }
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES) . "\n\n";

        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }
}
