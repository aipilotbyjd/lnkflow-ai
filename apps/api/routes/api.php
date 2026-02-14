<?php

use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\V1\ActivityLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\ConnectorReliabilityController;
use App\Http\Controllers\Api\V1\CredentialController;
use App\Http\Controllers\Api\V1\CredentialTypeController;
use App\Http\Controllers\Api\V1\ExecutionController;
use App\Http\Controllers\Api\V1\ExecutionDebuggerController;
use App\Http\Controllers\Api\V1\ExecutionRunbookController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\JobCallbackController;
use App\Http\Controllers\Api\V1\NodeController;
use App\Http\Controllers\Api\V1\OptimizationController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TagController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VariableController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Controllers\Api\V1\WorkflowApprovalController;
use App\Http\Controllers\Api\V1\WorkflowContractController;
use App\Http\Controllers\Api\V1\WorkflowController;
use App\Http\Controllers\Api\V1\WorkflowImportExportController;
use App\Http\Controllers\Api\V1\WorkflowTemplateController;
use App\Http\Controllers\Api\V1\WorkflowVersionController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use App\Http\Controllers\Api\V1\WorkspaceEnvironmentController;
use App\Http\Controllers\Api\V1\WorkspaceMemberController;
use App\Http\Controllers\Api\V1\WorkspacePolicyController;
use App\Http\Controllers\Api\WebhookReceiverController;
use App\Http\Middleware\VerifyEngineCallbackSignature;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->as('v1.')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Health Check (for Docker/K8s)
    |--------------------------------------------------------------------------
    */

    Route::get('health', fn () => response()->json(['status' => 'ok', 'timestamp' => now()->toIso8601String()]))->name('health');

    /*
    |--------------------------------------------------------------------------
    | Public Routes
    |--------------------------------------------------------------------------
    */

    Route::get('plans', [PlanController::class, 'index'])->name('plans.index');

    /*
    |--------------------------------------------------------------------------
    | Auth Routes (Guest)
    |--------------------------------------------------------------------------
    */

    Route::prefix('auth')->as('auth.')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->name('register');
        Route::post('login', [AuthController::class, 'login'])->name('login');
        Route::post('refresh', [AuthController::class, 'refreshToken'])->name('refresh');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('reset-password');
    });

    Route::get('verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware('signed')
        ->name('verification.verify');

    /*
    |--------------------------------------------------------------------------
    | Authenticated Routes
    |--------------------------------------------------------------------------
    */

    Route::middleware('auth:api')->group(function () {

        // Auth (Authenticated)
        Route::prefix('auth')->as('auth.')->group(function () {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::post('resend-verification-email', [AuthController::class, 'resendVerificationEmail'])->name('resend-verification');
        });

        // User Profile
        Route::prefix('user')->as('user.')->group(function () {
            Route::get('/', [UserController::class, 'show'])->name('show');
            Route::put('/', [UserController::class, 'update'])->name('update');
            Route::put('password', [UserController::class, 'changePassword'])->name('password');
            Route::post('avatar', [UserController::class, 'uploadAvatar'])->name('avatar.upload');
            Route::delete('avatar', [UserController::class, 'deleteAvatar'])->name('avatar.delete');
            Route::delete('/', [UserController::class, 'destroy'])->name('destroy');
        });

        // Invitation Accept/Decline (authenticated)
        Route::prefix('invitations/{token}')->as('invitations.')->group(function () {
            Route::post('accept', [InvitationController::class, 'accept'])->name('accept');
            Route::post('decline', [InvitationController::class, 'decline'])->name('decline');
        });

        // Workspaces
        Route::apiResource('workspaces', WorkspaceController::class)->only(['index', 'store']);

        // Workspace Nested Routes (all require workspace membership)
        Route::prefix('workspaces/{workspace}')->as('workspaces.')
            ->middleware('workspace.role')
            ->scopeBindings()
            ->group(function () {

                // Workspace CRUD (show, update, delete)
                Route::get('/', [WorkspaceController::class, 'show'])->name('show');
                Route::put('/', [WorkspaceController::class, 'update'])->name('update');
                Route::delete('/', [WorkspaceController::class, 'destroy'])->name('destroy');

                // Members
                Route::prefix('members')->as('members.')->group(function () {
                    Route::get('/', [WorkspaceMemberController::class, 'index'])->name('index');
                    Route::put('{user}', [WorkspaceMemberController::class, 'update'])->name('update');
                    Route::delete('{user}', [WorkspaceMemberController::class, 'destroy'])->name('destroy');
                });

                // Leave Workspace
                Route::post('leave', [WorkspaceMemberController::class, 'leave'])->name('leave');

                // Invitations
                Route::prefix('invitations')->as('invitations.')->group(function () {
                    Route::get('/', [InvitationController::class, 'index'])->name('index');
                    Route::post('/', [InvitationController::class, 'store'])->name('store');
                    Route::delete('{invitation}', [InvitationController::class, 'destroy'])->name('destroy');
                });

                // Subscription
                Route::prefix('subscription')->as('subscription.')->group(function () {
                    Route::get('/', [SubscriptionController::class, 'show'])->name('show');
                    Route::post('/', [SubscriptionController::class, 'store'])->name('store');
                    Route::delete('/', [SubscriptionController::class, 'destroy'])->name('destroy');
                });

                // Workflows
                Route::apiResource('workflows', WorkflowController::class);
                Route::post('workflows/{workflow}/activate', [WorkflowController::class, 'activate'])->name('workflows.activate');
                Route::post('workflows/{workflow}/deactivate', [WorkflowController::class, 'deactivate'])->name('workflows.deactivate');
                Route::post('workflows/{workflow}/duplicate', [WorkflowController::class, 'duplicate'])->name('workflows.duplicate');

                // Credentials
                Route::apiResource('credentials', CredentialController::class);
                Route::post('credentials/{credential}/test', [CredentialController::class, 'test'])->name('credentials.test');

                // Executions
                Route::get('executions/stats', [ExecutionController::class, 'stats'])->name('executions.stats');
                Route::apiResource('executions', ExecutionController::class)->only(['index', 'show', 'destroy']);
                Route::get('executions/{execution}/nodes', [ExecutionController::class, 'nodes'])->name('executions.nodes');
                Route::get('executions/{execution}/logs', [ExecutionController::class, 'logs'])->name('executions.logs');
                Route::get('executions/{execution}/replay-pack', [ExecutionController::class, 'replayPack'])->name('executions.replay-pack');
                Route::post('executions/{execution}/retry', [ExecutionController::class, 'retry'])->name('executions.retry');
                Route::post('executions/{execution}/rerun-deterministic', [ExecutionController::class, 'rerunDeterministic'])->name('executions.rerun-deterministic');
                Route::post('executions/{execution}/cancel', [ExecutionController::class, 'cancel'])->name('executions.cancel');
                Route::post('workflows/{workflow}/execute', [ExecutionController::class, 'store'])->name('workflows.execute');
                Route::get('workflows/{workflow}/executions', [ExecutionController::class, 'workflowExecutions'])->name('workflows.executions');
                Route::get('executions/{execution}/debug/timeline', [ExecutionDebuggerController::class, 'timeline'])->name('executions.debug.timeline');
                Route::get('executions/{execution}/debug/snapshot', [ExecutionDebuggerController::class, 'snapshot'])->name('executions.debug.snapshot');
                Route::get('executions/{execution}/debug/diff', [ExecutionDebuggerController::class, 'diff'])->name('executions.debug.diff');

                // Webhooks
                Route::apiResource('webhooks', WebhookController::class);
                Route::post('webhooks/{webhook}/regenerate-uuid', [WebhookController::class, 'regenerateUuid'])->name('webhooks.regenerate-uuid');
                Route::post('webhooks/{webhook}/activate', [WebhookController::class, 'activate'])->name('webhooks.activate');
                Route::post('webhooks/{webhook}/deactivate', [WebhookController::class, 'deactivate'])->name('webhooks.deactivate');
                Route::get('workflows/{workflow}/webhook', [WebhookController::class, 'forWorkflow'])->name('workflows.webhook');

                // Variables
                Route::apiResource('variables', VariableController::class);

                // Tags
                Route::apiResource('tags', TagController::class)->except(['show']);

                // Activity Logs
                Route::get('activity', [ActivityLogController::class, 'index'])->name('activity.index');

                // Billing
                Route::prefix('billing')->as('billing.')->group(function () {
                    Route::get('/', [BillingController::class, 'show'])->name('show');
                    Route::post('checkout', [BillingController::class, 'createCheckoutSession'])->name('checkout');
                    Route::post('portal', [BillingController::class, 'createPortalSession'])->name('portal');
                    Route::post('cancel', [BillingController::class, 'cancel'])->name('cancel');
                    Route::post('resume', [BillingController::class, 'resume'])->name('resume');
                    Route::post('change-plan', [BillingController::class, 'changePlan'])->name('change-plan');
                });

                // Workflow Versions
                Route::prefix('workflows/{workflow}/versions')->as('workflows.versions.')->group(function () {
                    Route::get('/', [WorkflowVersionController::class, 'index'])->name('index');
                    Route::post('/', [WorkflowVersionController::class, 'store'])->name('store');
                    Route::get('compare', [WorkflowVersionController::class, 'compare'])->name('compare');
                    Route::get('{version}', [WorkflowVersionController::class, 'show'])->name('show');
                    Route::post('{version}/publish', [WorkflowVersionController::class, 'publish'])->name('publish');
                    Route::post('{version}/restore', [WorkflowVersionController::class, 'restore'])->name('restore');
                });

                // Workflow Contracts
                Route::post('workflows/{workflow}/contracts/validate', [WorkflowContractController::class, 'validate'])->name('workflows.contracts.validate');
                Route::get('workflows/{workflow}/contracts/latest', [WorkflowContractController::class, 'latest'])->name('workflows.contracts.latest');
                Route::post('contracts/tests/run', [WorkflowContractController::class, 'runTests'])->name('contracts.tests.run');

                // Human Approval Inbox
                Route::get('approvals', [WorkflowApprovalController::class, 'index'])->name('approvals.index');
                Route::get('approvals/{approval}', [WorkflowApprovalController::class, 'show'])->name('approvals.show');
                Route::post('approvals/{approval}/decision', [WorkflowApprovalController::class, 'decide'])->name('approvals.decision');

                // Workspace Policy Engine
                Route::get('policy', [WorkspacePolicyController::class, 'show'])->name('policy.show');
                Route::put('policy', [WorkspacePolicyController::class, 'upsert'])->name('policy.upsert');

                // Connector Reliability
                Route::get('connectors/reliability', [ConnectorReliabilityController::class, 'index'])->name('connectors.reliability');
                Route::get('connectors/reliability/{connectorKey}/attempts', [ConnectorReliabilityController::class, 'attempts'])->name('connectors.reliability.attempts');

                // Optimizer
                Route::get('optimizations', [OptimizationController::class, 'index'])->name('optimizations.index');
                Route::post('optimizations/executions/{execution}/estimate', [OptimizationController::class, 'estimateExecution'])->name('optimizations.executions.estimate');

                // Git-native Environments
                Route::get('environments', [WorkspaceEnvironmentController::class, 'index'])->name('environments.index');
                Route::post('environments', [WorkspaceEnvironmentController::class, 'store'])->name('environments.store');
                Route::post('workflows/{workflow}/environments/promote', [WorkspaceEnvironmentController::class, 'promote'])->name('workflows.environments.promote');
                Route::post('workflows/{workflow}/environments/rollback', [WorkspaceEnvironmentController::class, 'rollback'])->name('workflows.environments.rollback');
                Route::get('workflows/{workflow}/environments/releases', [WorkspaceEnvironmentController::class, 'releases'])->name('workflows.environments.releases');

                // Failure Runbooks
                Route::get('runbooks', [ExecutionRunbookController::class, 'index'])->name('runbooks.index');
                Route::get('runbooks/{runbook}', [ExecutionRunbookController::class, 'show'])->name('runbooks.show');
                Route::post('runbooks/{runbook}/acknowledge', [ExecutionRunbookController::class, 'acknowledge'])->name('runbooks.acknowledge');
                Route::post('runbooks/{runbook}/resolve', [ExecutionRunbookController::class, 'resolve'])->name('runbooks.resolve');

                // Workflow Import/Export
                Route::get('workflows/{workflow}/export', [WorkflowImportExportController::class, 'export'])->name('workflows.export');
                Route::post('workflows/export-bulk', [WorkflowImportExportController::class, 'exportBulk'])->name('workflows.export-bulk');
                Route::post('workflows/import', [WorkflowImportExportController::class, 'import'])->name('workflows.import');

                // Use Template (requires workspace context to create workflow)
                Route::post('templates/{slug}/use', [WorkflowTemplateController::class, 'use'])->name('templates.use');
            });

        // Nodes (Global - not workspace-scoped)
        Route::prefix('nodes')->as('nodes.')->group(function () {
            Route::get('/', [NodeController::class, 'index'])->name('index');
            Route::get('categories', [NodeController::class, 'categories'])->name('categories');
            Route::get('search', [NodeController::class, 'search'])->name('search');
            Route::get('{type}', [NodeController::class, 'show'])->name('show');
        });

        // Credential Types (Global)
        Route::prefix('credential-types')->as('credential-types.')->group(function () {
            Route::get('/', [CredentialTypeController::class, 'index'])->name('index');
            Route::get('{type}', [CredentialTypeController::class, 'show'])->name('show');
        });

        // Workflow Templates (Global — browse only, no workspace needed)
        Route::prefix('templates')->as('templates.')->group(function () {
            Route::get('/', [WorkflowTemplateController::class, 'index'])->name('index');
            Route::get('categories', [WorkflowTemplateController::class, 'categories'])->name('categories');
            Route::get('{slug}', [WorkflowTemplateController::class, 'show'])->name('show');
        });
    });
});

/*
|--------------------------------------------------------------------------
| Public Webhook Receiver Routes
|--------------------------------------------------------------------------
*/

Route::prefix('webhooks')->as('webhooks.')->group(function () {
    Route::any('{uuid}', [WebhookReceiverController::class, 'handle'])
        ->name('receive')
        ->whereUuid('uuid');
    Route::any('{uuid}/{path}', [WebhookReceiverController::class, 'handle'])
        ->name('receive.path')
        ->whereUuid('uuid');
});

/*
|--------------------------------------------------------------------------
| Job Callback Routes (Go Engine)
|--------------------------------------------------------------------------
*/

Route::prefix('v1/jobs')
    ->as('v1.jobs.')
    ->middleware(VerifyEngineCallbackSignature::class)
    ->group(function () {
        Route::post('callback', [JobCallbackController::class, 'handle'])->name('callback');
        Route::post('progress', [JobCallbackController::class, 'progress'])->name('progress');
    });

/*
|--------------------------------------------------------------------------
| Stripe Webhook Routes
|--------------------------------------------------------------------------
*/

Route::post('stripe/webhook', [StripeWebhookController::class, 'handle'])->name('stripe.webhook');
