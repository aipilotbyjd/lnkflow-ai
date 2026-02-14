<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CredentialType extends Model
{
    protected $fillable = [
        'type',
        'name',
        'description',
        'icon',
        'color',
        'fields_schema',
        'test_config',
        'docs_url',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fields_schema' => 'array',
            'test_config' => 'array',
        ];
    }

    /**
     * @return HasMany<Credential, $this>
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(Credential::class, 'type', 'type');
    }

    /**
     * @return array<string>
     */
    public function getSecretFields(): array
    {
        $fields = [];
        $properties = $this->fields_schema['properties'] ?? [];

        foreach ($properties as $key => $field) {
            if (isset($field['secret']) && $field['secret'] === true) {
                $fields[] = $key;
            }
        }

        return $fields;
    }
}
