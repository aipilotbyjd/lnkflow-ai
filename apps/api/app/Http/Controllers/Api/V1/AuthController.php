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
use App\Services\Auth\PassportTokenException;
use App\Services\Auth\PassportTokenService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Laravel\Passport\Passport;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, PassportTokenService $passportTokenService): JsonResponse
    {
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

        $user->sendEmailVerificationNotification();

        try {
            $token = $passportTokenService->issueToken(
                $user->email,
                (string) $request->validated('password')
            );
        } catch (PassportTokenException $e) {
            report($e);

            return response()->json([
                'message' => 'User registered successfully, but token issuance failed.',
            ], 500);
        }

        return response()->json([
            'message' => 'User registered successfully. Please check your email to verify your account.',
            'user' => new UserResource($user),
            ...$token,
        ], 201);
    }

    public function login(LoginRequest $request, PassportTokenService $passportTokenService): JsonResponse
    {
        $validated = $request->validated();

        try {
            $token = $passportTokenService->issueToken(
                $validated['email'],
                $validated['password']
            );
        } catch (PassportTokenException $e) {
            if ($e->oauthError === 'invalid_grant') {
                return response()->json([
                    'message' => 'Invalid credentials.',
                ], 401);
            }

            report($e);

            return response()->json([
                'message' => 'Unable to complete login.',
            ], 500);
        }

        $user = User::query()->where('email', $validated['email'])->first();
        if (! $user instanceof User) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        return response()->json([
            'message' => 'Login successful.',
            'user' => new UserResource($user),
            ...$token,
        ]);
    }

    public function refreshToken(RefreshTokenRequest $request, PassportTokenService $passportTokenService): JsonResponse
    {
        try {
            $token = $passportTokenService->refreshToken(
                (string) $request->validated('refresh_token')
            );
        } catch (PassportTokenException $e) {
            if ($e->oauthError === 'invalid_grant') {
                return response()->json([
                    'message' => 'Invalid or expired refresh token.',
                ], 401);
            }

            report($e);

            return response()->json([
                'message' => 'Unable to refresh access token.',
            ], 500);
        }

        return response()->json([
            'message' => 'Token refreshed successfully.',
            ...$token,
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

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'message' => 'Password reset link sent to your email.',
            ]);
        }

        return response()->json([
            'message' => __($status),
        ], 400);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();
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
}
