@props([
    'name',
    'url' => null,
    'sub' => null,
])

{{--
  A person in a list: initials in a coloured disc, the name beside it, and an
  optional second line under it.

  INITIALS, NOT A PHOTOGRAPH. Nobody in this LGU uploads one, and a column of
  identical placeholder silhouettes is worse than no avatar at all -- it adds a
  column of noise that distinguishes nobody.

  The disc itself is <x-avatar>, which is where the initials and the colour are
  worked out. It was pulled out of here when the thread list needed the mark
  without the name attached to it; keeping one definition is what makes a
  person the same colour on every page. See PersonRowTest.
--}}

<span class="person">
    <x-avatar :name="$name" />
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
