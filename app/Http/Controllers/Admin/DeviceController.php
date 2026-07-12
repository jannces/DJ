<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuthorizedDevice;
use App\Services\Security\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class DeviceController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request): View
    {
        $devices = AuthorizedDevice::with('registrar')
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where(fn ($w) =>
                $w->where('ip_address', 'like', "%{$s}%")->orWhere('hostname', 'like', "%{$s}%")))
            ->when(! $request->boolean('archived'), fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('hostname')->paginate(15)->withQueryString();

        $enforcement = \App\Models\SystemSetting::get('security.device_enforcement', false);

        return view('admin.devices.index', compact('devices', 'enforcement'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ip_address' => ['required', 'ip', 'unique:authorized_devices,ip_address'],
            'hostname' => ['required', 'string', 'max:150'],
            'mac_address' => ['nullable', 'string', 'max:17'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        $data['status'] = 'active';
        $data['registered_by'] = $request->user()->id;
        $device = AuthorizedDevice::create($data);
        Cache::forget("device.{$data['ip_address']}");
        $this->audit->log('device_registered', $device, [], $data);

        return back()->with('status', 'Device registered.');
    }

    public function update(Request $request, AuthorizedDevice $device): RedirectResponse
    {
        $data = $request->validate([
            'hostname' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        $device->update($data);

        return back()->with('status', 'Device updated.');
    }

    public function toggle(AuthorizedDevice $device): RedirectResponse
    {
        $new = $device->status === 'active' ? 'inactive' : 'active';
        $device->update(['status' => $new]);
        Cache::forget("device.{$device->ip_address}");
        $this->audit->log('device_'.($new === 'active' ? 'activated' : 'deactivated'), $device);

        return back()->with('status', "Device {$new}.");
    }

    public function archive(AuthorizedDevice $device): RedirectResponse
    {
        $device->update(['archived_at' => now(), 'status' => 'inactive']);
        Cache::forget("device.{$device->ip_address}");
        $this->audit->log('device_archived', $device);

        return back()->with('status', 'Device archived.');
    }
}
