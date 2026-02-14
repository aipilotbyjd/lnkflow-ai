<?php

namespace App\Services;

use App\Models\Credential;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class CredentialTestService
{
    /**
     * @return array{success: bool, message: string, response?: array<string, mixed>}
     */
    public function test(Credential $credential): array
    {
        $credentialType = $credential->credentialType;

        if (! $credentialType || empty($credentialType->test_config)) {
            return [
                'success' => false,
                'message' => 'This credential type does not support testing.',
            ];
        }

        $testConfig = $credentialType->test_config;
        $credentialData = $credential->getDecryptedData();

        return match ($testConfig['method'] ?? 'http') {
            'http' => $this->testHttp($testConfig, $credentialData),
            'smtp_connect' => $this->testSmtp($credentialData),
            default => [
                'success' => false,
                'message' => 'Unknown test method.',
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $testConfig
     * @param  array<string, mixed>  $credentialData
     * @return array{success: bool, message: string, response?: array<string, mixed>}
     */
    private function testHttp(array $testConfig, array $credentialData): array
    {
        $url = $testConfig['url'] ?? '';
        $headers = $this->interpolateHeaders($testConfig['headers'] ?? [], $credentialData);

        try {
            $response = Http::withHeaders($headers)
                ->timeout(10)
                ->get($url);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => $testConfig['success_message'] ?? 'Credential test successful.',
                    'response' => $response->json() ?? [],
                ];
            }

            return [
                'success' => false,
                'message' => 'Credential test failed: '.$response->status().' '.$response->reason(),
            ];
        } catch (ConnectionException $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $credentialData
     * @return array{success: bool, message: string}
     */
    private function testSmtp(array $credentialData): array
    {
        $host = $credentialData['host'] ?? '';
        $port = (int) ($credentialData['port'] ?? 587);
        $encryption = $credentialData['encryption'] ?? 'tls';
        $username = $credentialData['username'] ?? '';
        $password = $credentialData['password'] ?? '';

        try {
            $transport = new EsmtpTransport(
                $host,
                $port,
                $encryption === 'tls'
            );

            $transport->setUsername($username);
            $transport->setPassword($password);
            $transport->start();
            $transport->stop();

            return [
                'success' => true,
                'message' => 'SMTP connection successful.',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'SMTP connection failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $credentialData
     * @return array<string, string>
     */
    private function interpolateHeaders(array $headers, array $credentialData): array
    {
        $result = [];

        foreach ($headers as $key => $value) {
            $result[$key] = preg_replace_callback(
                '/\{\{(\w+)\}\}/',
                fn ($matches) => $credentialData[$matches[1]] ?? '',
                $value
            );
        }

        return $result;
    }
}
