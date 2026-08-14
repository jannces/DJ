@php
$map = [
  'pending'=>'info','dept_review'=>'info','hr_review'=>'info','final_review'=>'info',
  'approved'=>'success','rejected'=>'danger','returned'=>'warning','cancelled'=>'dark',
];
$labels = [
  'pending'=>'Pending Approval','dept_review'=>'Pending Approval','hr_review'=>'Pending Approval','final_review'=>'Pending Approval',
  'approved'=>'Approved','rejected'=>'Disapproved','returned'=>'Returned','cancelled'=>'Cancelled',
];
@endphp
<span class="badge bg-{{ $map[$status] ?? 'secondary' }}">{{ $labels[$status] ?? ucfirst($status) }}</span>
