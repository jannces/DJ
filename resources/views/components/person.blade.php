@props([
    'name',
    'url' => null,
    'sub' => null,
])

{{--
  A person in a list: initials in a coloured disc, the name beside it, and an
  optional second line under it.

  INITIALS, NOT A PHOTOGRAPH. Nobody in this LGU uploads one, and a column of
  identical placeholder silhouettes is worse than no avatar at all -- it adds
  a column of noise that distinguishes nobody.

  THE COLOUR COMES FROM THE NAME, not from the row's position. Position was
  the first version, and it meant the same person was orange on the rankings
  and green on the employee list, and changed colour when a filter reordered
  the page. Keyed off the name, a person keeps one colour everywhere, which is
  the only way the disc helps you find somebody you have seen before. It still
  claims no meaning -- five hues, assigned by a hash.

  Extracted so the two pages that show people share one definition rather than
  one copying the other's `rk-` classes. See PersonRowTest.
--}}

@php
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $initials = mb_strtoupper(
        mb_substr($parts[0] ?? '', 0, 1)
        .(count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '')
    );
    // crc32 rather than a running index: stable for a given name, across
    // pages and across whatever order the list happens to be in.
    $hue = crc32(mb_strtolower(trim($name))) % 5;
@endphp

<span class="person">
    <span class="person-av" data-n="{{ $hue }}" aria-hidden="true">{{ $initials }}</span>
    <span class="person-id">
        @if ($url)
            <a href="{{ $url }}" class="person-name name-link">{{ $name }}</a>
        @else
            <span class="person-name">{{ $name }}</span>
        @endif
        @if ($sub)
            <span class="person-sub">{{ $sub }}</span>
        @endif
    </span>
</span>
