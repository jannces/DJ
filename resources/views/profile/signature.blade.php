@extends('layouts.app')
@section('title', 'My Signature')
@section('content')

{{--
  One page, one job: the image that goes on the applicant's line of CSC Form
  No. 6.

  A file rather than a drawing pad. A signature drawn with a finger does not
  match the one on the rest of an employee's file, and a canvas would be the
  only screen here that stops working with scripts off. A photograph of a
  signature on paper is what the office already has.

  There is no employee selector on this page and no id in the form. It always
  acts on the signed-in account -- see SignatureController.
--}}

<h1 class="h4 mb-3">My Signature</h1>

{{-- No status banner here. layouts/app.blade.php already feeds
     session('status') to the toast, so this page was announcing every save
     twice: a green bar under the heading and a toast in the corner, both
     carrying the same sentence. --}}

<div class="card">
    <div class="card-body">
        @if ($profile === null)
            <p class="text-muted mb-0">
                Your account has no employee record yet, so there is nothing to sign
                applications with. Ask HR to complete your record first.
            </p>
        @else
            <div class="sig-layout">
                <div class="sig-preview">
                    <p class="sig-cap">On file</p>
                    @if ($profile->signature_path)
                        {{--
                          Drawn from the same private route the officers use, so
                          there is exactly one way a signature reaches a screen
                          and exactly one place that decides who may see it.
                          Never a link into storage/.
                        --}}
                        <div class="sig-box">
                            <img src="{{ route('signature.mine') }}" alt="Your signature as it will appear on your applications">
                        </div>
                        <p class="sig-meta">
                            Uploaded {{ $profile->signature_uploaded_at?->format('d M Y, g:ia') ?? 'earlier' }}
                        </p>
                    @else
                        <div class="sig-box sig-box-empty">
                            <span>No signature on file</span>
                        </div>
                        <p class="sig-meta">
                            Until you add one, your applications print your typed name on
                            the applicant's line.
                        </p>
                    @endif
                </div>

                <div class="sig-form">
                    <form method="POST" action="{{ route('signature.store') }}" enctype="multipart/form-data">
                        @csrf
                        <label for="signature" class="form-label">
                            {{ $profile->signature_path ? 'Replace it' : 'Add your signature' }}
                        </label>
                        <input id="signature" type="file" name="signature"
                               accept="image/png,image/jpeg"
                               class="form-control @error('signature') is-invalid @enderror" required>
                        @error('signature')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror

                        <p class="form-text">
                            Sign a blank sheet of white paper, photograph or scan it, and
                            upload it here. PNG or JPG, up to 2&nbsp;MB. A photo taken in
                            good light with the paper filling the frame reads best on the
                            printed form.
                        </p>

                        <button class="btn btn-lgu btn-sm">
                            <i class="bi bi-upload"></i>{{ $profile->signature_path ? 'Replace signature' : 'Save signature' }}
                        </button>
                    </form>

                    @if ($profile->signature_path)
                        <form method="POST" action="{{ route('signature.destroy') }}" class="mt-3"
                              data-confirm="Remove your signature? Applications you have already filed keep the signature they were filed with; new ones will print your typed name until you add another."
                              data-confirm-tone="danger">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger">Remove signature</button>
                        </form>
                    @endif
                </div>
            </div>

            {{-- Said plainly, because "who can see my signature" is the first
                 question anyone sensibly asks before uploading one. --}}
            <p class="sig-note">
                Your signature is stored outside the public folder and is never
                given a public link. It is shown only to you, to HR, and to the
                head of your own office &mdash; and only on an application you
                have actually filed.
            </p>
        @endif
    </div>
</div>
@endsection
