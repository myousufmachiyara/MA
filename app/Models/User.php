<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Http\Request;

class User extends Authenticatable
{
    use HasApiTokens, HasRoles, Notifiable;

    protected $fillable = [
        'name', 'username', 'email', 'password',
        'user_type', 'mobile_role', 'phone', 'cnic', 'employee_code', 'assigned_area',
        'device_id', 'fcm_token', 'app_version',
        'is_active', 'profile_photo',
        'created_by', 'updated_by',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active'          => 'boolean',
        'last_login_at'       => 'datetime',
        'last_active_at'       => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────

    public function activityLogs()
    {
        return $this->hasMany(UserActivityLog::class);
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeBookers($query)
    {
        return $query->where('user_type', 'mobile');
    }

    public function scopeWebUsers($query)
    {
        return $query->where('user_type', 'web');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * Log any user activity (login, logout, order taken, sync, etc.)
     * Called from AuthController now; will be reused by the Sale Order
     * module for booker order-taking activity later.
     */
    public function recordActivity(string $type, ?string $description = null, ?Request $request = null, array $meta = [])
    {
        return $this->activityLogs()->create([
            'activity_type' => $type,
            'description'   => $description,
            'ip_address'    => $request?->ip(),
            'device_id'     => $this->device_id,
            'app_version'   => $this->app_version,
            'meta'          => $meta ?: null,
        ]);
    }
}