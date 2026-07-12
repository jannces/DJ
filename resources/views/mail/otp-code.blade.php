<x-mail::message>
# One-Time Password

Hello {{ $user->name }},

Use this code to finish signing in to the **LGU Alicia Leave Management System**:

<x-mail::panel>
<span style="font-size:28px;letter-spacing:8px;font-weight:bold;">{{ $code }}</span>
</x-mail::panel>

The code expires in **{{ $ttlMinutes }} minutes** and can be used only once.
If you did not try to sign in, contact the System Administrator immediately.

Thanks,<br>{{ config('app.name') }}
</x-mail::message>
