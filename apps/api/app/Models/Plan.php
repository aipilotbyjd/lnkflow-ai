<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    /** @use HasFactory<\Database\Factories\PlanFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price_monthly',
        'price_yearly',
        'limits',
        'features',
        'is_active',
        'sort_order',
        'stripe_product_id',
        'stripe_prices',
        'credit_tiers',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'limits' => 'array',
            'features' => 'array',
            'stripe_prices' => 'array',
            'credit_tiers' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getLimit(string $key): mixed
    {
        return ($this->limits ?? [])[$key] ?? null;
    }

    public function hasFeature(string $key): mixed
    {
        return ($this->features ?? [])[$key] ?? false;
    }

    /**
     * Get the Stripe monthly price ID from the stripe_prices JSON.
     */
    public function getStripePriceMonthlyIdAttribute(): ?string
    {
        return $this->stripe_prices['monthly'] ?? null;
    }

    /**
     * Get the Stripe yearly price ID from the stripe_prices JSON.
     */
    public function getStripePriceYearlyIdAttribute(): ?string
    {
        return $this->stripe_prices['yearly'] ?? null;
    }

    /**
     * Get available credit tier add-on amounts.
     *
     * @return array<int>
     */
    public function getCreditTierOptions(): array
    {
        return $this->credit_tiers ?? [];
    }
}
