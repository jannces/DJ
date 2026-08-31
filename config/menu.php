<?php

/*
 * Sidebar menu. Visibility is permission-driven (RBAC menu visibility):
 * an item renders only when the signed-in user holds `permission`.
 *
 * `permission` may be a single slug or a list, in which case holding any one of
 * them is enough — for a page several roles reach for different reasons.
 *
 * An item may also carry `requires_any`: a list of which the user needs at
 * least one, ON TOP OF `permission`. It exists for the case where an item is
 * permitted but has nothing behind it for this particular role. The two are not
 * the same thing: one widens who may see an entry, the other narrows it.
 */
return [
    // `dashboard.view` says they may open a dashboard; `requires_any` says
    // there is one here for them. The System Administrator holds the first and
    // neither of the second — /dashboard redirects them to the Security
    // Dashboard, which is its own entry further down — so this item would be a
    // second link to a page they already have.
    [
        'label' => 'Dashboard', 'icon' => 'bi-speedometer2', 'route' => 'dashboard',
        'permission' => 'dashboard.view',
        'requires_any' => ['leave.view-own', 'leave.requests.view-all'],
    ],

    // Sits in the top group beside Dashboard rather than down in
    // Administration, because for the System Administrator this IS their
    // dashboard — it is where /dashboard sends them, and it took the slot the
    // plain Dashboard entry vacated for that role.
    //
    // It has to live above the first heading, not merely above Reports: an item
    // placed between two headings renders underneath the one before it, so
    // dropping it just above "Reports" would file it under HR Management for
    // anybody who can see that section.
    ['label' => 'Security Dashboard', 'icon' => 'bi-shield-exclamation', 'route' => 'security.dashboard', 'permission' => 'security.dashboard'],

    ['heading' => 'Leave'],
    ['label' => 'Apply for Leave', 'icon' => 'bi-calendar-plus', 'route' => 'leave.create', 'permission' => 'leave.apply'],
    ['label' => 'My Leave Requests', 'icon' => 'bi-card-checklist', 'route' => 'leave.index', 'permission' => 'leave.view-own'],
    // "My Balances" was removed: leave credits and credit history now live on the
    // employee dashboard (single location, one query path — see DashboardService).
    // One approval queue shared by Mayor, Vice Mayor and HR. Department Head is
    // no longer an approver, so it has no review entry.
    // Two permissions, either of which is enough: the Mayor and HR decide,
    // the Department Head recommends for their own office. One page, one entry
    // — the entry is not new and nothing else moved.
    ['label' => 'Leave Approvals', 'icon' => 'bi-clipboard-check', 'route' => 'review.index',
        'permission' => ['leave.approve.final', 'leave.review.department']],
    ['label' => 'All Leave Requests', 'icon' => 'bi-collection', 'route' => 'leave.all', 'permission' => 'leave.requests.view-all'],

    ['heading' => 'HR Management'],
    ['label' => 'Employees', 'icon' => 'bi-person-badge', 'route' => 'employees.index', 'permission' => 'employees.view'],
    ['label' => 'Departments', 'icon' => 'bi-diagram-3', 'route' => 'departments.index', 'permission' => 'departments.manage'],
    ['label' => 'Positions', 'icon' => 'bi-briefcase', 'route' => 'positions.index', 'permission' => 'positions.manage'],
    ['label' => 'Leave Balances', 'icon' => 'bi-calculator', 'route' => 'balances.index', 'permission' => 'leave.balances.manage'],
    // Days used per employee, per type. Two permissions, either of which is
    // enough: HR sees every office, a head sees the one they head.
    ['label' => 'Leave Rankings', 'icon' => 'bi-bar-chart-steps', 'route' => 'rankings.index',
        'permission' => ['employees.view', 'leave.review.department']],
    ['label' => 'Leave Types', 'icon' => 'bi-list-check', 'route' => 'leave-types.index', 'permission' => 'leave-types.manage'],
    ['label' => 'Holidays', 'icon' => 'bi-calendar-event', 'route' => 'holidays.index', 'permission' => 'holidays.manage'],

    ['heading' => 'Reports'],
    ['label' => 'Reports', 'icon' => 'bi-file-earmark-bar-graph', 'route' => 'reports.index', 'permission' => 'reports.generate'],

    ['heading' => 'Administration'],
    ['label' => 'Users', 'icon' => 'bi-people-fill', 'route' => 'users.index', 'permission' => 'users.manage'],
    ['label' => 'Roles & Permissions', 'icon' => 'bi-shield-lock', 'route' => 'roles.index', 'permission' => 'rbac.manage'],
    ['label' => 'Authorized Devices', 'icon' => 'bi-pc-display', 'route' => 'devices.index', 'permission' => 'devices.manage'],
    ['label' => 'Blocked IPs', 'icon' => 'bi-slash-circle', 'route' => 'security.blocked-ips', 'permission' => 'security.blocked-ips'],
    ['label' => 'Intrusion Logs', 'icon' => 'bi-bug', 'route' => 'security.intrusions', 'permission' => 'security.intrusions'],
    ['label' => 'Audit Logs', 'icon' => 'bi-journal-text', 'route' => 'audit.index', 'permission' => 'audit.view'],
    ['label' => 'Activity Logs', 'icon' => 'bi-clock-history', 'route' => 'activity.index', 'permission' => 'activity.view'],
    ['label' => 'System Settings', 'icon' => 'bi-gear', 'route' => 'settings.index', 'permission' => 'settings.manage'],
];
