@php
$map = [
  'pending'=>'secondary','dept_review'=>'info','hr_review'=>'primary','final_review'=>'warning',
  'approved'=>'success','rejected'=>'danger','returned'=>'warning','cancelled'=>'dark',
];
$labels = [
  'pending'=>'Pending','dept_review'=>'Dept. Review','hr_review'=>'HR Review','final_review'=>'Final Review',
  'approved'=>'Approved','rejected'=>'Disapproved','returned'=>'Returned','cancelled'=>'Cancelled',
];
@endphp
<span class="badge bg-{{ $map[$status] ?? 'secondary' }}">{{ $labels[$status] ?? ucfirst($status) }}</span>
