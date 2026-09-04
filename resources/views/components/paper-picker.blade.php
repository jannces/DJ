@props(['request'])

{{--
  Which paper the PDF comes out on.

  A split button rather than four separate ones: the download is one action,
  and the size is a detail of it. Long bond is the primary because that is the
  paper the LGU files this form on; the rest are one click away rather than
  four buttons wide.

  Plain links, so this works with the keyboard and with JavaScript off. The
  server allowlists the value either way -- see LeaveRequestController::
  paperSize(), which will not pass a query string into dompdf's setPaper().
--}}

<div class="btn-group">
    <a href="{{ route('leave.form6', $request) }}" class="btn btn-lgu btn-sm" target="_blank">
        {{-- "Download Form", not "Download PDF": this button existed before the
             picker did and its label is what the rest of the system, and
             ApprovalAuthorityTest, calls it. Renaming it was not part of
             adding a paper size. --}}
        <i class="bi bi-download me-1" aria-hidden="true"></i>Download Form
    </a>
    <button type="button" class="btn btn-lgu btn-sm dropdown-toggle dropdown-toggle-split"
            data-bs-toggle="dropdown" aria-expanded="false">
        <span class="visually-hidden">Choose a paper size</span>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li><h6 class="dropdown-header">Paper size</h6></li>
        {{-- Ordered longest first, which is also most-used first here: the
             plain Download button already gives you Legal, so the sizes
             below it read as the alternatives to it. --}}
        @foreach ([
            'legal' => ['Legal / long bond', '8.5 × 14 in', true],
            'folio' => ['Folio / short bond', '8.5 × 13 in', false],
            'a4' => ['A4', '210 × 297 mm', false],
            'letter' => ['Letter', '8.5 × 11 in', false],
        ] as $value => [$label, $size, $isDefault])
            <li>
                <a class="dropdown-item paper-item"
                   href="{{ route('leave.form6', [$request, 'paper' => $value]) }}" target="_blank">
                    <span>{{ $label }}@if ($isDefault)<span class="paper-default">default</span>@endif</span>
                    <span class="paper-dim">{{ $size }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</div>
