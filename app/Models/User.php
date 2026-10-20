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

#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'google_id', 'preferred_locale'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    public const ROLE_USER = 'user';

    public const ROLE_ADMIN = 'admin';

    public const PLAN_FREE = 'free';

    public const PLAN_LITE = 'lite';

    public const PLAN_PREMIUM = 'premium';

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
            'stripe_current_period_end' => 'datetime',
            'stripe_subscription_event_created_at' => 'datetime',
            'stripe_cancel_at_period_end' => 'boolean',
            'stripe_subscription_schedule_id' => 'string',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function preferredLocale(): string
    {
        return in_array($this->preferred_locale, ['en', 'ja'], true)
            ? $this->preferred_locale
            : 'en';
    }

    /** @return HasMany<ZenConsultation, $this> */
    public function zenConsultations(): HasMany
    {
        return $this->hasMany(ZenConsultation::class);
    }
}
