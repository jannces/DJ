@props(['name'])

{{--
  Initials in a coloured disc.

  Extracted from the person component because the thread list needs the disc on
  its own -- the mark sits at the left of the row and the name at the right, so
  they cannot come from one wrapper any more. Copying the hash into a second
  file would have meant a person was one colour on the employee list and
  another in the inbox, which is exactly the bug this hash was written to fix.

  THE COLOUR COMES FROM THE NAME, not from the row's position: keyed off the
  name, a person keeps one colour everywhere, which is the only way the disc
  helps you recognise somebody you have seen before. It claims no meaning --
  five hues, assigned by crc32.

  Decorative by default. Wherever the name is written out beside it, announcing
  the initials as well reads the name twice, the second time as two letters.
--}}

@php
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $initials = mb_strtoupper(
        mb_substr($parts[0] ?? '', 0, 1)
        .(count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '')
    );
    $hue = crc32(mb_strtolower(trim($name))) % 5;
@endphp

{{-- One line on purpose: the disc's markup is matched verbatim by several
     tests, and wrapping the attributes would put a newline inside the tag. --}}
<span {{ $attributes->merge(['class' => 'person-av']) }} data-n="{{ $hue }}" aria-hidden="true">{{ $initials }}</span>
