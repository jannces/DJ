# UI Redesign & Human–Computer Interaction (HCI) Principles

The interface was redesigned into a professional, government-grade design system.
This document records the design decisions and maps them to established HCI principles
(Nielsen's 10 Usability Heuristics, Shneiderman's 8 Golden Rules, and WCAG accessibility).

## 1. Design system
- **Design tokens** (`public/css/app.css`): a single source of colors, typography scale,
  spacing, radius, shadows and motion — guarantees visual **consistency** everywhere.
- **Palette:** deep government green (`#14532d`) with a gold accent (`#ca8a04`); refined,
  accessible light and dark themes.
- **Typography:** clear hierarchy (page titles → section headers → body → captions).
- **Components:** cards, stat cards, buttons, inputs, tables, badges, dropdowns, modals,
  timeline, skeleton loaders, breadcrumbs — all uniform.

## 2. HCI principles applied

| Principle (Nielsen / Shneiderman / WCAG) | How the redesign applies it |
|---|---|
| **Visibility of system status** (N1) | Loading spinner overlay, skeleton loaders, hover/active/focus states, live intrusion badge, toast feedback on every action |
| **Match between system & real world** (N2) | Plain government language, CSC Form 6 layout mirrors the paper form, familiar icons |
| **User control & freedom** (N3) | Cancel buttons, "return for revision", theme toggle, collapsible sidebar, breadcrumbs to go back |
| **Consistency & standards** (N4) | One design-token system; identical components and spacing across all pages |
| **Error prevention** (N5) | Distinct danger styling, confirmation dialogs on destructive actions, inline validation, disabled states |
| **Recognition rather than recall** (N6) | Persistent labelled sidebar (icon **+** text), breadcrumbs, pre-filled forms, visible search |
| **Flexibility & efficiency** (N7) | Global search, keyboard-focusable controls, remembered theme, shortcuts to common actions |
| **Aesthetic & minimalist design** (N8) | Generous whitespace, restrained palette, only essential information per screen |
| **Help users recover from errors** (N9) | Friendly error pages (403/404/blocked), clear validation messages with guidance |
| **Help & documentation** (N10) | Contextual hints, form help text, in-app manuals |
| **Offer informative feedback** (Shneiderman) | Toasts, status badges, progress indicators |
| **Design dialogs to yield closure** (Shneiderman) | Multi-step approval workflow shows a clear completed state + notification |
| **Support internal locus of control** (Shneiderman) | Users initiate actions; the system reacts predictably |
| **Reduce short-term memory load** (Shneiderman) | Information grouped into cards; balances/among visible where needed |

## 3. Accessibility (WCAG 2.1 AA)
- **Contrast:** text/background pairs meet AA in both light and dark themes.
- **Focus visibility:** a high-contrast gold focus ring on every interactive element.
- **Touch targets:** buttons/inputs ≥ 42px.
- **Semantics:** `aria-label`, `aria-current`, `role="search"`, labelled inputs.
- **Reduced motion:** animations are disabled for users who set `prefers-reduced-motion`.
- **Keyboard:** all actions reachable and operable by keyboard.

## 4. Responsiveness
- Fluid layout with a collapsible sidebar; on small screens the sidebar becomes an
  overlay drawer with a backdrop. Tables scroll horizontally inside their container.

## 5. Files changed (UI only — no backend impact)
- `public/css/app.css` — new design system
- `public/js/app.js` — theme-aware charts, mobile drawer, feedback helpers
- `resources/views/layouts/app.blade.php`, `layouts/guest.blade.php`
- `resources/views/partials/sidebar.blade.php`, `topbar.blade.php`, `page-head.blade.php`
- `resources/views/auth/login.blade.php`, `auth/otp.blade.php`
- `resources/views/dashboard/index.blade.php`

All other pages inherit the new look automatically through the shared layout and design
tokens. The redesign changed **no** controllers, models, routes or database — verified by
the full test suite (49 passing).
