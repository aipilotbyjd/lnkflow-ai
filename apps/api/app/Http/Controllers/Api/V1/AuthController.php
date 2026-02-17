<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RefreshTokenRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Laravel\Passport\Passport;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::query()->create([
                'first_name' => $request->validated('first_name'),
                'last_name' => $request->validated('last_name'),
                'email' => $request->validated('email'),
                'password' => Hash::make($request->validated('password')),
            ]);

            $workspace = Workspace::query()->create([
                'name' => $user->first_name."'s Workspace",
                'owner_id' => $user->id,
            ]);

            $workspace->members()->attach($user->id, [
                'role' => 'owner',
                'joined_at' => now(),
            ]);

            $freePlan = Plan::query()->where('slug', 'free')->first();
            if ($freePlan) {
                $workspace->subscription()->create([
                    'plan_id' => $freePlan->id,
                    'status' => SubscriptionStatus::Active,
                    'current_period_start' => now(),
                    'current_period_end' => now()->addYear(),
                ]);
            }

            return $user;
        });

        $user->sendEmailVerificationNotification();

        // Issue tokens via Password Grant
        $tokenResponse = $this->issuePasswordGrantToken(
            $user->email,
            (string) $request->validated('password')
        );

        if ($tokenResponse === null) {
            return response()->json([
                'message' => 'User registered successfully, but token issuance failed.',
            ], 500);
        }

        return response()->json([
            'message' => 'User registered successfully. Please check your email to verify your account.',
            'user' => new UserResource($user),
            ...$tokenResponse,
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::query()->where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $tokenResponse = $this->issuePasswordGrantToken(
            $validated['email'],
            $validated['password']
        );

        if ($tokenResponse === null) {
            return response()->json([
                'message' => 'Unable to complete login.',
            ], 500);
        }

        return response()->json([
            'message' => 'Login successful.',
            'user' => new UserResource($user),
            ...$tokenResponse,
        ]);
    }

    public function refreshToken(RefreshTokenRequest $request): JsonResponse
    {
        $response = Http::asForm()->post(config('app.url').'/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $request->validated('refresh_token'),
            'client_id' => config('passport.password_client_id'),
            'client_secret' => config('passport.password_client_secret'),
            'scope' => '',
        ]);

        if ($response->failed()) {
            return response()->json([
                'message' => 'Invalid or expired refresh token.',
            ], 401);
        }

        return response()->json([
            'message' => 'Token refreshed successfully.',
            ...$response->json(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $accessToken = $request->user()?->token();

        if ($accessToken !== null) {
            $accessToken->revoke();

            $accessTokenId = $accessToken->oauth_access_token_id ?? $accessToken->id ?? null;
            if (is_string($accessTokenId) && $accessTokenId !== '') {
                Passport::refreshToken()->newQuery()
                    ->where('access_token_id', $accessTokenId)
                    ->update(['revoked' => true]);
            }
        }

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()),
        ]);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink(
            $request->only('email')
        );

        // Always return the same message to prevent account enumeration
        return response()->json([
            'message' => 'If an account with that email exists, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();

                // Revoke all existing tokens on password reset
                $user->tokens()->delete();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Password has been reset successfully.',
            ]);
        }

        return response()->json([
            'message' => __($status),
        ], 400);
    }

    public function verifyEmail(Request $request, int $id, string $hash): JsonResponse
    {
        $user = User::findOrFail($id);

        if (! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            return response()->json([
                'message' => 'Invalid verification link.',
            ], 400);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email already verified.',
            ]);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json([
            'message' => 'Email verified successfully.',
        ]);
    }

    public function resendVerificationEmail(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email already verified.',
            ], 400);
        }

        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'Verification email sent.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Private Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Issue access + refresh tokens via Passport's Password Grant.
     *
     * @return array<string, mixed>|null
     */
    private function issuePasswordGrantToken(string $email, string $password): ?array
    {
        $response = Http::asForm()->post(config('app.url').'/oauth/token', [
            'grant_type' => 'password',
            'client_id' => config('passport.password_client_id'),
            'client_secret' => config('passport.password_client_secret'),
            'username' => $email,
            'password' => $password,
            'scope' => '',
        ]);

        if ($response->failed()) {
            report('Passport token issuance failed: '.$response->body());

            return null;
        }

        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'token_type' => $data['token_type'],
            'expires_in' => $data['expires_in'],
        ];
    }
}
