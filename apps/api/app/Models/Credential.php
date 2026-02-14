<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class Credential extends Model
{
    /** @use HasFactory<\Database\Factories\CredentialFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'created_by',
        'name',
        'type',
        'data',
        'last_used_at',
        'expires_at',
    ];

    protected $hidden = [
        'data',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<CredentialType, $this>
     */
    public function credentialType(): BelongsTo
    {
        return $this->belongsTo(CredentialType::class, 'type', 'type');
    }

    /**
     * @return BelongsToMany<Workflow, $this>
     */
    public function workflows(): BelongsToMany
    {
        return $this->belongsToMany(Workflow::class, 'workflow_credentials')
            ->withPivot('node_id')
            ->withTimestamps();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function setDataAttribute(array $data): void
    {
        $this->attributes['data'] = Crypt::encryptString(json_encode($data));
    }

    /**
     * @return array<string, mixed>
     */
    public function getDecryptedData(): array
    {
        if (empty($this->attributes['data'])) {
            return [];
        }

        $decrypted = Crypt::decryptString($this->attributes['data']);

        return json_decode($decrypted, true) ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMaskedData(): array
    {
        $data = $this->getDecryptedData();
        $type = $this->credentialType;

        if (! $type) {
            return $this->maskAllValues($data);
        }

        $secretFields = $type->getSecretFields();

        foreach ($data as $key => $value) {
            if (in_array($key, $secretFields) && is_string($value) && strlen($value) > 4) {
                $data[$key] = str_repeat('•', 8).substr($value, -4);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function maskAllValues(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value) && strlen($value) > 4) {
                $data[$key] = str_repeat('•', 8).substr($value, -4);
            }
        }

        return $data;
    }

    public function markAsUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
