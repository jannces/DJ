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
    protected $fillable = ['name', 'slug', 'description', 'parent_id', 'is_system'];

    protected $casts = ['is_system' => 'boolean'];

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
