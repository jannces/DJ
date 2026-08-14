<?php

/*
 * Sidebar menu. Visibility is permission-driven (RBAC menu visibility):
 * an item renders only when the signed-in user holds `permission`.
 */
return [
    ['label' => 'Dashboard', 'icon' => 'bi-speedometer2', 'route' => 'dashboard', 'permission' => 'dashboard.view'],

    ['heading' => 'Leave'],
    ['label' => 'Apply for Leave', 'icon' => 'bi-calendar-plus', 'route' => 'leave.create', 'permission' => 'leave.apply'],
    ['label' => 'My Leave Requests', 'icon' => 'bi-card-checklist', 'route' => 'leave.index', 'permission' => 'leave.view-own'],
    // "My Balances" was removed: leave credits and credit history now live on the
    // employee dashboard (single location, one query path — see DashboardService).
    // One approval queue shared by Mayor, Vice Mayor and HR. Department Head is
    // no longer an approver, so it has no review entry.
    ['label' => 'Leave Approvals', 'icon' => 'bi-clipboard-check', 'route' => 'review.index', 'permission' => 'leave.approve.final'],
    ['label' => 'All Leave Requests', 'icon' => 'bi-collection', 'route' => 'leave.all', 'permission' => 'leave.requests.view-all'],


    ['heading' => 'HR Management'],
    ['label' => 'Employees', 'icon' => 'bi-person-badge', 'route' => 'employees.index', 'permission' => 'employees.view'],
    ['label' => 'Departments', 'icon' => 'bi-diagram-3', 'route' => 'departments.index', 'permission' => 'departments.manage'],
    ['label' => 'Positions', 'icon' => 'bi-briefcase', 'route' => 'positions.index', 'permission' => 'positions.manage'],
    ['label' => 'Leave Balances', 'icon' => 'bi-calculator', 'route' => 'balances.index', 'permission' => 'leave.balances.manage'],
    ['label' => 'Leave Types', 'icon' => 'bi-list-check', 'route' => 'leave-types.index', 'permission' => 'leave-types.manage'],
    ['label' => 'Holidays', 'icon' => 'bi-calendar-event', 'route' => 'holidays.index', 'permission' => 'holidays.manage'],

    ['heading' => 'Reports'],
    ['label' => 'Reports', 'icon' => 'bi-file-earmark-bar-graph', 'route' => 'reports.index', 'permission' => 'reports.generate'],

    ['heading' => 'Administration'],
    ['label' => 'Users', 'icon' => 'bi-people-fill', 'route' => 'users.index', 'permission' => 'users.manage'],
    ['label' => 'Roles & Permissions', 'icon' => 'bi-shield-lock', 'route' => 'roles.index', 'permission' => 'rbac.manage'],
    ['label' => 'Authorized Devices', 'icon' => 'bi-pc-display', 'route' => 'devices.index', 'permission' => 'devices.manage'],
    ['label' => 'Security Dashboard', 'icon' => 'bi-shield-exclamation', 'route' => 'security.dashboard', 'permission' => 'security.dashboard'],
    ['label' => 'Blocked IPs', 'icon' => 'bi-slash-circle', 'route' => 'security.blocked-ips', 'permission' => 'security.blocked-ips'],
    ['label' => 'Intrusion Logs', 'icon' => 'bi-bug', 'route' => 'security.intrusions', 'permission' => 'security.intrusions'],
    ['label' => 'Audit Logs', 'icon' => 'bi-journal-text', 'route' => 'audit.index', 'permission' => 'audit.view'],
    ['label' => 'Activity Logs', 'icon' => 'bi-clock-history', 'route' => 'activity.index', 'permission' => 'activity.view'],
    ['label' => 'System Settings', 'icon' => 'bi-gear', 'route' => 'settings.index', 'permission' => 'settings.manage'],
];
