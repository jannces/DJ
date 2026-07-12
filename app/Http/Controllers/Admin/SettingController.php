<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\Security\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(): View
    {
        $groups = SystemSetting::orderBy('group')->orderBy('key')->get()->groupBy('group');

        return view('admin.settings.index', compact('groups'));
    }

    public function update(Request $request): RedirectResponse
    {
        $settings = SystemSetting::all();
        $changed = [];

        foreach ($settings as $setting) {
            $field = str_replace('.', '__', $setting->key);
            $new = $setting->type === 'bool'
                ? ($request->boolean($field) ? '1' : '0')
                : $request->input($field, $setting->value);

            if ((string) $new !== (string) $setting->value) {
                $changed[$setting->key] = ['old' => $setting->value, 'new' => $new];
                $setting->update(['value' => $new]);
            }
        }

        if ($changed) {
            $this->audit->log('settings_updated', null, [], $changed);
        }

        return back()->with('status', 'Settings saved.');
    }
}
