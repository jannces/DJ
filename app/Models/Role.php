<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use Auditable;

    /**
     * The five roles the LGU has, in the order they are offered.
     *
     * All five are assignable: these are the account types, fixed by the
     * organisation's structure rather than by anything in this application, and
     * there is no sixth to invent — the Roles page offers no way to create one.
     *
     * Ordered as the organisation reads: an employee, the head of their office,
     * HR, the Mayor, and the administrator who runs the system.
     *
     * Still a separate list from "every row in the roles table", and
     * UserController still validates submissions against it, because the two
     * being the same today is a fact about the data rather than a guarantee.
     */
    public const ASSIGNABLE = ['employee', 'department-head', 'hr', 'mayor', 'system-admin'];

    protected $fillable = ['name', 'slug', 'description', 'parent_id', 'is_system'];

    protected $casts = ['is_system' => 'boolean'];

    /** The roles an administrator may hand out, in the declared order. */
    public function scopeAssignable($query)
    {
        return $query->whereIn('slug', self::ASSIGNABLE)
            ->orderByRaw('CASE slug '.collect(self::ASSIGNABLE)
                ->map(fn ($slug, $i) => "WHEN '{$slug}' THEN {$i}")
                ->implode(' ').' ELSE 99 END');
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Own permissions plus every ancestor's, following the parent chain. */
    public function effectivePermissionSlugs(): array
    {
        $slugs = $this->permissions()->pluck('slug')->all();
        $seen = [$this->id];
        $parent = $this->parent;
        while ($parent && ! in_array($parent->id, $seen, true)) {
            $seen[] = $parent->id;
            $slugs = array_merge($slugs, $parent->permissions()->pluck('slug')->all());
            $parent = $parent->parent;
        }

        return array_values(array_unique($slugs));
    }
}
