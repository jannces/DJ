<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
    use Auditable;
    protected $fillable = ['key', 'value', 'type', 'group', 'description'];

    /** Typed, cached read; falls back to $default when the key is absent. */
    public static function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::remember('system_settings.all', 300, function () {
            return static::query()->get()->keyBy('key');
        });

        $setting = $all->get($key);
        if (! $setting) {
            return $default;
        }

        return match ($setting->type) {
            'int' => (int) $setting->value,
            'bool' => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($setting->value, true),
            default => $setting->value,
        };
    }

    public static function set(string $key, mixed $value): void
    {
        $stored = is_array($value) ? json_encode($value) : (string) $value;
        static::query()->where('key', $key)->update(['value' => $stored]);
        Cache::forget('system_settings.all');
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('system_settings.all'));
        static::deleted(fn () => Cache::forget('system_settings.all'));
    }
}
