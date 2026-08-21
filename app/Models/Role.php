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
     * The roles a user account can actually be given, in the order they are
     * offered.
     *
     * Department Head is organisational structure, not an account type — it
     * holds no permission of its own since the approval workflow stopped using
     * it. Super Admin is the unrestricted platform owner and is not something
     * an administrator hands out from a form; it is set up once, at install.
     * Neither belongs in a picker, and neither is accepted from a submission —
     * see UserController, which validates against this list rather than
     * against every row in the table.
     */
    public const ASSIGNABLE = ['mayor', 'vice-mayor', 'hr', 'employee', 'system-admin'];

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
