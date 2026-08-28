@php
    /**
     * The registration fields.
     *
     * `old()` in every value and an @error on every field: a duplicate IP is
     * the commonest thing to get wrong here, and until now a rejected
     * registration came back as a blank form with nothing said about why.
     */
@endphp

<div class="mb-3">
    <label class="form-label" for="dev-ip">IP address <span class="req">*</span></label>
    <input id="dev-ip" name="ip_address" required
           class="form-control @error('ip_address') is-invalid @enderror"
           value="{{ old('ip_address') }}" placeholder="192.168.1.10">
    @error('ip_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
    <div class="form-text">The address this office computer holds on the LAN.</div>
</div>

<div class="mb-3">
    <label class="form-label" for="dev-host">Hostname <span class="req">*</span></label>
    <input id="dev-host" name="hostname" required maxlength="150"
           class="form-control @error('hostname') is-invalid @enderror"
           value="{{ old('hostname') }}" placeholder="HR-PC-01">
    @error('hostname')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label class="form-label" for="dev-mac">MAC address</label>
    <input id="dev-mac" name="mac_address" maxlength="17"
           class="form-control @error('mac_address') is-invalid @enderror"
           value="{{ old('mac_address') }}" placeholder="00:1A:2B:3C:4D:5E">
    @error('mac_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-0">
    <label class="form-label" for="dev-desc">Description</label>
    <input id="dev-desc" name="description" maxlength="255"
           class="form-control @error('description') is-invalid @enderror"
           value="{{ old('description') }}" placeholder="HR front desk">
    @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
