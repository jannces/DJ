@props([
    'name',
    'label',
    'options' => [],
    'any' => 'Any',
])

{{--
  One dropdown in a list's toolbar.

  The label lives in the first option rather than above the control, so the
  toolbar stays one line: "Status: Any" reads as both the label and the
  current value, and the row does not grow a second line of small captions.

  $options is value => text. Values are compared as strings so an integer id
  from the database still matches what came back in the query string.
--}}

<select name="{{ $name }}" class="form-select form-select-sm toolbar-select"
        aria-label="{{ $label }}">
    <option value="">{{ $label }}: {{ $any }}</option>
    @foreach ($options as $value => $text)
        <option value="{{ $value }}" @selected((string) request($name) === (string) $value)>{{ $text }}</option>
    @endforeach
</select>
