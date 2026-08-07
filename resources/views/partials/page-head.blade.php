{{-- Usage: @include('partials.page-head', ['title'=>'...', 'sub'=>'...', 'crumbs'=>['Home'=>route('dashboard'),'Users'=>null]]) --}}
<div class="page-head">
    @isset($crumbs)
        <nav class="crumbs" aria-label="breadcrumb">
            @foreach ($crumbs as $label => $url)
                @if (!$loop->first)<span class="sep">/</span>@endif
                @if ($url)<a href="{{ $url }}">{{ $label }}</a>@else<span>{{ $label }}</span>@endif
            @endforeach
        </nav>
    @endisset
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h1>{{ $title }}</h1>
            @isset($sub)<div class="sub">{{ $sub }}</div>@endisset
        </div>
        @isset($actions){{ $actions }}@endisset
    </div>
</div>
