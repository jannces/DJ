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
        return view('admin.devices.index', $this->listing($request));
    }

    /**
     * The list, with the Register panel open.
     *
     * A real URL behind the button, so the page works with the script and
     * without it: with it, Bootstrap opens the panel and cancels the
     * navigation; without it, this renders the list with the panel already up.
     */
    public function create(Request $request): View
    {
        return view('admin.devices.index', $this->listing($request) + ['opening' => true]);
    }

    /**
     * One query shape, so index and create cannot show different lists.
     *
     * @return array{devices:mixed, enforcement:mixed}
     */
    private function listing(Request $request): array
    {
        $devices = AuthorizedDevice::with('registrar')
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where(fn ($w) =>
                $w->where('ip_address', 'like', "%{$s}%")->orWhere('hostname', 'like', "%{$s}%")))
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            // Archived was a link that could only be on or off; "all" is how
            // you compare what is retired against what is running.
            ->when($request->string('show')->toString() !== 'all', fn ($q) => $request->string('show')->toString() === 'archived'
                ? $q->whereNotNull('archived_at')
                : $q->whereNull('archived_at'))
            ->orderBy('hostname')->paginate(config('lists.per_page'))->withQueryString();

        return [
            'devices' => $devices,
            'enforcement' => \App\Models\SystemSetting::get('security.device_enforcement', false),
        ];
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
        // Registration is an activation -- store() has no way to create an
        // inactive device. Without this the column stays empty until somebody
        // happens to toggle it, and the list shows a dash for a device that
        // has been running since the day it was added.
        $data['activated_at'] = now();
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
        $activating = $device->status !== 'active';
        $new = $activating ? 'active' : 'inactive';

        // Only the direction just taken is stamped, so the pair reads as
        // "last switched on at X, last switched off at Y". Writing both would
        // make each overwrite the other's meaning.
        $changes = ['status' => $new];
        $changes[$activating ? 'activated_at' : 'deactivated_at'] = now();

        $device->update($changes);
        Cache::forget("device.{$device->ip_address}");
        $this->audit->log('device_'.($activating ? 'activated' : 'deactivated'), $device);

        return back()->with('status', "Device {$new}.");
    }

    public function archive(AuthorizedDevice $device): RedirectResponse
    {
        // Archiving is a deactivation: the active() scope excludes archived
        // rows, so the device stops being served the moment this runs. Leaving
        // deactivated_at empty would show it as never having been switched off.
        $device->update([
            'archived_at' => now(),
            'status' => 'inactive',
            'deactivated_at' => now(),
        ]);
        Cache::forget("device.{$device->ip_address}");
        $this->audit->log('device_archived', $device);

        return back()->with('status', 'Device archived.');
    }
}
