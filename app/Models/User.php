<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'google_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    public const ROLE_USER = 'user';

    public const ROLE_ADMIN = 'admin';

    public const PLAN_FREE = 'free';

    protected $attributes = [
        'plan' => self::PLAN_FREE,
        'daily_credits' => 0,
        'purchased_credits' => 0,
    ];

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'daily_credits_refreshed_at' => 'date',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function preferredLocale(): string
    {
        return 'ja';
    }

    /** @return HasMany<TarotReading, $this> */
    public function tarotReadings(): HasMany
    {
        return $this->hasMany(TarotReading::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Asked by the site header on every page, which must not fail because of the shop's tables.
     */
    public function hasOrders(): bool
    {
        return rescue(fn (): bool => $this->orders()->exists(), false);
    }
}
