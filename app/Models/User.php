<?php

namespace App\Models;

use App\Services\Rbac\RbacService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Traits\Auditable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use Auditable, HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [
        'name', 'username', 'email', 'password', 'status',
        'blocked_until', 'blocked_reason', 'failed_attempts',
        'must_change_password', 'password_changed_at',
        'last_login_at', 'last_login_ip',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'blocked_until' => 'datetime',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'must_change_password' => 'boolean',
            'password' => 'hashed',
        ];
    }

    // ---- Relations -------------------------------------------------------

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function directPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)->withPivot('type');
    }

    public function employeeProfile(): HasOne
    {
        return $this->hasOne(EmployeeProfile::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function leaveHistory(): HasMany
    {
        return $this->hasMany(LeaveHistory::class);
    }

    public function otpCodes(): HasMany
    {
        return $this->hasMany(OtpCode::class);
    }

    public function failedLogins(): HasMany
    {
        return $this->hasMany(FailedLogin::class);
    }

    // ---- RBAC ------------------------------------------------------------

    public function hasRole(string ...$slugs): bool
    {
        return app(RbacService::class)->userHasRole($this, $slugs);
    }

    public function hasPermission(string $slug): bool
    {
        return app(RbacService::class)->userHasPermission($this, $slug);
    }

    public function permissionSlugs(): array
    {
        return app(RbacService::class)->effectivePermissions($this);
    }

    // ---- State helpers ---------------------------------------------------

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED
            && ($this->blocked_until === null || $this->blocked_until->isFuture());
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function department(): ?Department
    {
        return $this->employeeProfile?->department;
    }

    public function headsDepartments(): HasMany
    {
        return $this->hasMany(Department::class, 'head_user_id');
    }
}
