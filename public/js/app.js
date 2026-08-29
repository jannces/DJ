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
  // Answerable yes or no, in those words. "Yes, proceed" against "Cancel" is
  // two different kinds of answer to one question; the pair has to match the
  // question being asked.
  //
  // The tone colours the Yes button after the thing it will do: red for a
  // block, an archive, a deletion; green for lifting a ban or restoring an
  // account. Same colour as the button that opened the dialog, so the answer
  // is confirming what was clicked rather than a generic proceed.
  var CONFIRM_TONES = { danger: '#be123c', success: '#15803d', brand: '#6d28d9' };

  window.lmsConfirm = function (opts) {
    var settings = opts || {};
    var tone = CONFIRM_TONES[settings.tone] || CONFIRM_TONES.danger;
    delete settings.tone;

    return Swal.fire(Object.assign({
      title: 'Are you sure?', icon: 'warning', showCancelButton: true,
      confirmButtonColor: tone, cancelButtonColor: '#6b7280',
      confirmButtonText: 'Yes', cancelButtonText: 'No', reverseButtons: true,
      // Carries the system's own panel styling, and the blur behind it. Scoped
      // to the confirmation so the toasts, which are the same library, are not
      // dragged into it.
      customClass: { container: 'lms-ask-bg', popup: 'lms-ask' }
    }, settings));
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

        var options = {
          text: form.dataset.confirm,
          tone: form.dataset.confirmTone || 'danger'
        };

        // Some decisions have to be written down as well as agreed to —
        // blocking an account is recorded against a reason. That belongs in
        // the question rather than in a second dialog after it: a browser
        // prompt is a stop that does not blur, does not follow the theme, and
        // was appearing BEFORE the confirmation, because an inline onsubmit is
        // bound before this listener is.
        if (form.dataset.confirmInput) {
          options.input = 'text';
          options.inputPlaceholder = form.dataset.confirmInput;
          options.inputAttributes = { 'aria-label': form.dataset.confirmInput };
          options.inputValidator = function (value) {
            if (!value || !value.trim()) return form.dataset.confirmInput + ' is required.';
          };
        }

        lmsConfirm(options).then(function (res) {
          if (!res.isConfirmed) return;
          if (form.dataset.confirmInput) {
            var field = form.querySelector('[name="' + form.dataset.confirmField + '"]');
            if (field) field.value = res.value;
          }
          form.dataset.confirmed = '1';
          form.submit();
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

    // A record panel the server said to open: the page is editing a record, or
    // the last submission was rejected and the errors are inside the panel.
    //
    // It has to be the server's decision, not the click's. A rejected form
    // redirects back to the page it came from, so a panel that only opened on
    // a click would be shut on arrival — errors invisible, typed values gone.
    document.querySelectorAll('.modal[data-open-on-load]').forEach(function (el) {
      bootstrap.Modal.getOrCreateInstance(el).show();
    });

    // Live filtering.
    //
    // The toolbar asks the server and swaps the rows it gets back, so a search
    // covers every record rather than the ten currently on screen. Filtering
    // the visible rows in the browser would be instant and wrong: search for a
    // name sitting on page three and it would answer "no matches".
    //
    // The form works without any of this — that is why the submit button is
    // removed here rather than left out of the markup. If the script does not
    // run, or a request fails, the page falls back to submitting normally.
    document.querySelectorAll('form[data-live-filter]').forEach(function (form) {
      var card = form.closest('.card');
      var list = card && card.querySelector('[data-list]');
      if (!list || !window.fetch || !window.AbortController) return;

      form.querySelectorAll('.toolbar-submit').forEach(function (b) { b.remove(); });

      var timer = null;
      var inflight = null;

      function url() {
        var params = new URLSearchParams();
        new FormData(form).forEach(function (value, key) {
          if (String(value) !== '') params.append(key, value);
        });
        var query = params.toString();
        return form.action + (query ? '?' + query : '');
      }

      function run() {
        var target = url();
        if (inflight) inflight.abort();
        inflight = new AbortController();
        list.setAttribute('aria-busy', 'true');

        fetch(target, {
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
          signal: inflight.signal
        }).then(function (response) {
          if (!response.ok) throw new Error(response.status);
          return response.text();
        }).then(function (html) {
          var next = new DOMParser().parseFromString(html, 'text/html')
            .querySelector('[data-list]');
          if (next) list.innerHTML = next.innerHTML;
          list.removeAttribute('aria-busy');
          // So the address bar matches what is shown: a refresh, a bookmark or
          // the back button all keep the filter.
          history.replaceState(null, '', target);
        }).catch(function (error) {
          if (error.name === 'AbortError') return;
          list.removeAttribute('aria-busy');
          form.submit();
        });
      }

      form.addEventListener('submit', function (event) {
        event.preventDefault();
        clearTimeout(timer);
        run();
      });

      // A dropdown is a decision already made; a search box is still being
      // typed, so it waits for a pause rather than asking on every keystroke.
      form.querySelectorAll('select').forEach(function (select) {
        select.addEventListener('change', run);
      });
      form.querySelectorAll('input[type="search"]').forEach(function (input) {
        input.addEventListener('input', function () {
          clearTimeout(timer);
          timer = setTimeout(run, 300);
        });
      });
    });
  });
})();

