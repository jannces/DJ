<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Rules\StrongPassword;
use App\Services\Security\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class PasswordChangeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function edit(): View
    {
        return view('auth.change-password');
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', new StrongPassword],
        ]);

        $request->user()->forceFill([
            'password' => Hash::make($request->string('password')),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        $this->audit->log('password_changed', $request->user());

        return redirect()->route('dashboard')->with('status', 'Password updated successfully.');
    }
}
