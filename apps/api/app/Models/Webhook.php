<?php

namespace App\Models;

use App\Enums\WebhookAuthType;
use App\Enums\WebhookResponseMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class Webhook extends Model
{
    /** @use HasFactory<\Database\Factories\WebhookFactory> */
    use HasFactory;

    protected $fillable = [
        'workflow_id',
        'workspace_id',
        'uuid',
        'path',
        'methods',
        'is_active',
        'auth_type',
        'auth_config',
        'rate_limit',
        'response_mode',
        'response_status',
        'response_body',
        'call_count',
        'last_called_at',
    ];

    protected $hidden = [
        'auth_config',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'methods' => 'array',
            'is_active' => 'boolean',
            'auth_type' => WebhookAuthType::class,
            'response_mode' => WebhookResponseMode::class,
            'response_body' => 'array',
            'last_called_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Webhook $webhook) {
            if (empty($webhook->uuid)) {
                $webhook->uuid = Str::uuid()->toString();
            }
        });
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function setAuthConfigAttribute(?array $config): void
    {
        if ($config === null) {
            $this->attributes['auth_config'] = null;

            return;
        }

        $this->attributes['auth_config'] = Crypt::encryptString(json_encode($config));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDecryptedAuthConfig(): ?array
    {
        if (empty($this->attributes['auth_config'])) {
            return null;
        }

        $decrypted = Crypt::decryptString($this->attributes['auth_config']);

        return json_decode($decrypted, true);
    }

    public function getUrl(): string
    {
        $baseUrl = config('app.url').'/webhooks/'.$this->uuid;

        if ($this->path) {
            return $baseUrl.'/'.$this->path;
        }

        return $baseUrl;
    }

    public function incrementCallCount(): void
    {
        $this->increment('call_count');
        $this->update(['last_called_at' => now()]);
    }

    public function isMethodAllowed(string $method): bool
    {
        return in_array(strtoupper($method), array_map('strtoupper', $this->methods));
    }

    public function activate(): void
    {
        $this->update(['is_active' => true]);
    }

    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }
}
