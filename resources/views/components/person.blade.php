@props([
    'name',
    'url' => null,
    'sub' => null,
    'avatar' => null,
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

  `avatar` exists for the one case where the written name is not the name the
  disc should be keyed off. The user list writes "Dela Cruz, Maria S." while
  every other page writes "Maria Dela Cruz" -- and since the colour is a hash
  of the string, keying it off the displayed text would give the same person
  two colours across two pages, which is precisely the bug the shared hash was
  written to stop. Pass the plain name here and the disc stays consistent.
--}}

<span class="person">
    <x-avatar :name="$avatar ?? $name" />
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
