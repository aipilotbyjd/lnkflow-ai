<?php

namespace App\Services\Auth;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

class PassportTokenService
{
    public function __construct(private readonly Kernel $kernel) {}

    /**
     * @return array<string, int|string|null>
     */
    public function issueToken(string $email, string $password): array
    {
        return $this->requestToken([
            'grant_type' => 'password',
            'username' => $email,
            'password' => $password,
            'scope' => '',
        ]);
    }

    /**
     * @return array<string, int|string|null>
     */
    public function refreshToken(string $refreshToken): array
    {
        return $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope' => '',
        ]);
    }

    /**
     * @param  array<string, string>  $payload
     * @return array<string, int|string|null>
     */
    protected function requestToken(array $payload): array
    {
        $clientId = (string) config('passport.password_client_id');
        $clientSecret = (string) config('passport.password_client_secret');

        if ($clientId === '' || $clientSecret === '') {
            throw new PassportTokenException(
                'server_error',
                'Passport password client credentials are not configured.',
                500
            );
        }

        $tokenRequest = Request::create('/oauth/token', 'POST', [
            ...$payload,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        $tokenRequest->headers->set('Accept', 'application/json');

        $response = $this->kernel->handle($tokenRequest);
        $decodedResponse = json_decode($response->getContent() ?: '{}', true);

        if ($response->getStatusCode() !== 200) {
            throw new PassportTokenException(
                is_array($decodedResponse) ? (string) ($decodedResponse['error'] ?? 'server_error') : 'server_error',
                is_array($decodedResponse)
                    ? (string) ($decodedResponse['error_description'] ?? 'Unable to issue OAuth token.')
                    : 'Unable to issue OAuth token.',
                $response->getStatusCode()
            );
        }

        if (
            ! is_array($decodedResponse)
            || ! isset($decodedResponse['access_token'], $decodedResponse['token_type'], $decodedResponse['expires_in'])
        ) {
            throw new PassportTokenException('server_error', 'Unexpected OAuth token response.', 500);
        }

        return [
            'access_token' => (string) $decodedResponse['access_token'],
            'refresh_token' => isset($decodedResponse['refresh_token']) ? (string) $decodedResponse['refresh_token'] : null,
            'token_type' => (string) $decodedResponse['token_type'],
            'expires_in' => (int) $decodedResponse['expires_in'],
        ];
    }
}
