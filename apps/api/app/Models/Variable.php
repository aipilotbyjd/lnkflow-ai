<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class Variable extends Model
{
    /** @use HasFactory<\Database\Factories\VariableFactory> */
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'created_by',
        'key',
        'value',
        'description',
        'is_secret',
    ];

    protected $hidden = [
        'value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_secret' => 'boolean',
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

    public function setValueAttribute(string $value): void
    {
        if ($this->is_secret ?? false) {
            $this->attributes['value'] = Crypt::encryptString($value);
        } else {
            $this->attributes['value'] = $value;
        }
    }

    public function getDecryptedValue(): string
    {
        if ($this->is_secret && ! empty($this->attributes['value'])) {
            return Crypt::decryptString($this->attributes['value']);
        }

        return $this->attributes['value'] ?? '';
    }

    public function getMaskedValue(): string
    {
        if ($this->is_secret) {
            $value = $this->getDecryptedValue();
            if (strlen($value) > 4) {
                return str_repeat('•', 8).substr($value, -4);
            }

            return str_repeat('•', strlen($value));
        }

        return $this->attributes['value'] ?? '';
    }
}
