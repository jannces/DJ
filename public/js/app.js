/* LGU Alicia LMS — shared frontend behaviors (offline, no CDN) */
(function () {
  'use strict';

  // ---- Theme (dark/light) ------------------------------------------------
  const stored = localStorage.getItem('lms-theme');
  const theme = stored || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  document.documentElement.setAttribute('data-bs-theme', theme);

  function syncThemeIcon() {
    const t = document.documentElement.getAttribute('data-bs-theme');
    document.querySelectorAll('.theme-icon').forEach(function (el) {
      el.className = 'theme-icon bi ' + (t === 'dark' ? 'bi-sun' : 'bi-moon-stars');
    });
  }

  window.lmsToggleTheme = function () {
    const next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-bs-theme', next);
    localStorage.setItem('lms-theme', next);
    syncThemeIcon();
  };

  // ---- Chart.js theme-aware global defaults ------------------------------
  function applyChartDefaults() {
    if (!window.Chart) return;
    const css = getComputedStyle(document.documentElement);
    const grid = css.getPropertyValue('--border').trim() || '#e3e8ef';
    const text = css.getPropertyValue('--muted').trim() || '#6b7280';
    Chart.defaults.font.family = "'Segoe UI', system-ui, sans-serif";
    Chart.defaults.font.size = 12;
    Chart.defaults.color = text;
    Chart.defaults.borderColor = grid;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.boxWidth = 8;
    Chart.defaults.plugins.legend.labels.padding = 14;
    Chart.defaults.plugins.tooltip.padding = 10;
    Chart.defaults.plugins.tooltip.cornerRadius = 8;
    Chart.defaults.plugins.tooltip.titleFont = { weight: '700' };
    Chart.defaults.maintainAspectRatio = false;
  }
  applyChartDefaults();
  window.lmsChartPalette = ['#6d28d9', '#f5c518', '#9d5cf0', '#a16207', '#be123c', '#7c3aed', '#15803d', '#c4b5fd'];

  // ---- CSRF-aware fetch helper -------------------------------------------
  window.lmsFetch = function (url, options) {
    options = options || {};
    options.headers = Object.assign({
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
      'Accept': 'application/json'
    }, options.headers || {});
    return fetch(url, options).then(function (r) {
      if (r.status === 419) { window.location.reload(); }
      return r;
    });
  };

  // ---- Toasts (SweetAlert2) ----------------------------------------------
  window.lmsToast = function (icon, title) {
    Swal.fire({
      toast: true, position: 'top-end', showConfirmButton: false,
      timer: 3800, timerProgressBar: true, icon: icon, title: title
    });
  };
  window.lmsConfirm = function (opts) {
    return Swal.fire(Object.assign({
      title: 'Are you sure?', icon: 'warning', showCancelButton: true,
      confirmButtonColor: '#6d28d9', cancelButtonColor: '#6b7280',
      confirmButtonText: 'Yes, proceed', reverseButtons: true
    }, opts || {}));
  };

  document.addEventListener('DOMContentLoaded', function () {
    syncThemeIcon();

    const flash = document.getElementById('lms-flash');
    if (flash) {
      if (flash.dataset.success) lmsToast('success', flash.dataset.success);
      if (flash.dataset.error) lmsToast('error', flash.dataset.error);
      if (flash.dataset.warning) lmsToast('warning', flash.dataset.warning);
    }

    // Sidebar toggle (+ mobile backdrop)
    const sb = document.getElementById('lmsSidebar');
    function closeMobile() { sb && sb.classList.remove('show-mobile'); const b = document.querySelector('.sidebar-backdrop'); if (b) b.remove(); }
    document.querySelectorAll('[data-toggle-sidebar]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (window.innerWidth < 1100) {
          const showing = sb.classList.toggle('show-mobile');
          if (showing) {
            const bd = document.createElement('div'); bd.className = 'sidebar-backdrop';
            bd.addEventListener('click', closeMobile); document.body.appendChild(bd);
          } else closeMobile();
        } else {
          sb.classList.toggle('collapsed');
        }
      });
    });

    // Confirmation forms
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        if (form.dataset.confirmed) return;
        e.preventDefault();
        lmsConfirm({ text: form.dataset.confirm }).then(function (res) {
          if (res.isConfirmed) { form.dataset.confirmed = '1'; form.submit(); }
        });
      });
    });

    // Page loader on genuine navigations (not AJAX/confirm forms)
    document.querySelectorAll('form:not([data-no-loader]):not([data-confirm])').forEach(function (form) {
      form.addEventListener('submit', function () {
        const loader = document.getElementById('page-loader');
        if (loader) loader.classList.add('active');
      });
    });

    // Real-time intrusion alert polling (admins only)
    const bell = document.getElementById('alert-bell');
    if (bell) {
      const poll = function () {
        lmsFetch(bell.dataset.url).then(function (r) { return r.ok ? r.json() : null; }).then(function (data) {
          if (!data) return;
          const badge = document.getElementById('alert-badge');
          if (data.unseen > 0) {
            badge.textContent = data.unseen > 99 ? '99+' : data.unseen;
            badge.classList.remove('d-none');
            if (data.latest && data.latest.id !== Number(bell.dataset.lastId || 0)) {
              if (bell.dataset.lastId) lmsToast('warning', 'Intrusion alert: ' + data.latest.category + ' from ' + data.latest.ip);
              bell.dataset.lastId = data.latest.id;
            }
          } else { badge.classList.add('d-none'); }
        }).catch(function () {});
      };
      poll(); setInterval(poll, Number(bell.dataset.interval || 15) * 1000);
    }

    // Real-time leave notifications (every signed-in user). Lets a decision
    // made on one machine surface on another without a page refresh.
    const notifBell = document.getElementById('notif-bell');
    if (notifBell) {
      const badge = document.getElementById('notif-badge');
      // Seeded from the server-rendered badge so the first poll does not
      // re-toast notifications that were already unread when the page loaded.
      let lastId = null, primed = false;
      const pollNotifs = function () {
        lmsFetch(notifBell.dataset.url).then(function (r) { return r.ok ? r.json() : null; }).then(function (data) {
          if (!data) return;
          if (data.unread > 0) {
            badge.textContent = data.unread > 99 ? '99+' : data.unread;
            badge.classList.remove('d-none');
          } else { badge.classList.add('d-none'); }

          if (data.latest && primed && data.latest.id !== lastId) {
            lmsToast('info', data.latest.title);
          }
          lastId = data.latest ? data.latest.id : null;
          primed = true;
        }).catch(function () {});
      };
      pollNotifs(); setInterval(pollNotifs, Number(notifBell.dataset.interval || 15) * 1000);
    }
  });
})();
