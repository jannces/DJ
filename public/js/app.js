/* LGU Alicia LMS — shared frontend behaviors (offline, no CDN) */
(function () {
  'use strict';

  // ---- Theme (dark/light) ------------------------------------------------
  const stored = localStorage.getItem('lms-theme');
  const theme = stored || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  document.documentElement.setAttribute('data-bs-theme', theme);

  window.lmsToggleTheme = function () {
    const next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-bs-theme', next);
    localStorage.setItem('lms-theme', next);
    document.querySelectorAll('.theme-icon').forEach(function (el) {
      el.className = 'theme-icon bi ' + (next === 'dark' ? 'bi-sun' : 'bi-moon-stars');
    });
  };

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
      timer: 3500, timerProgressBar: true, icon: icon, title: title
    });
  };

  window.lmsConfirm = function (opts) {
    return Swal.fire(Object.assign({
      title: 'Are you sure?', icon: 'warning', showCancelButton: true,
      confirmButtonColor: '#14532d', cancelButtonColor: '#6b7280',
      confirmButtonText: 'Yes, proceed'
    }, opts || {}));
  };

  document.addEventListener('DOMContentLoaded', function () {
    // Flash messages from the server become toasts.
    const flash = document.getElementById('lms-flash');
    if (flash) {
      if (flash.dataset.success) lmsToast('success', flash.dataset.success);
      if (flash.dataset.error) lmsToast('error', flash.dataset.error);
      if (flash.dataset.warning) lmsToast('warning', flash.dataset.warning);
    }

    // Sidebar toggle
    const sb = document.querySelector('.lms-sidebar');
    document.querySelectorAll('[data-toggle-sidebar]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (window.innerWidth < 992) sb.classList.toggle('show-mobile');
        else sb.classList.toggle('collapsed');
      });
    });

    // Confirmation forms: <form data-confirm="message">
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        if (form.dataset.confirmed) return;
        e.preventDefault();
        lmsConfirm({ text: form.dataset.confirm }).then(function (res) {
          if (res.isConfirmed) { form.dataset.confirmed = '1'; form.submit(); }
        });
      });
    });

    // Page loader on normal form submissions
    document.querySelectorAll('form:not([data-no-loader])').forEach(function (form) {
      form.addEventListener('submit', function () {
        const loader = document.getElementById('page-loader');
        if (loader && form.dataset.confirmed !== undefined || loader && !form.dataset.confirm) {
          loader.classList.add('active');
        }
      });
    });

    // ---- Real-time intrusion alert polling (admins only) -----------------
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
              if (bell.dataset.lastId) {
                lmsToast('warning', 'Intrusion alert: ' + data.latest.category + ' from ' + data.latest.ip);
              }
              bell.dataset.lastId = data.latest.id;
            }
          } else {
            badge.classList.add('d-none');
          }
        }).catch(function () { /* offline-tolerant */ });
      };
      poll();
      setInterval(poll, Number(bell.dataset.interval || 15) * 1000);
    }
  });
})();
