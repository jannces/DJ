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

  // ---- Two channels, because there are two kinds of message ---------------
  //
  // "User updated." and "Intrusion alert: sqli from 203.0.113.9" were sharing
  // one toast: same corner, same 3.8-second timer. One is a receipt for
  // something you just did and is harmless to miss -- the list shows the
  // change. The other is something happening elsewhere, and missing it means
  // missing an attack. Those want opposite treatment, so they are separate.

  // A receipt. Bottom-right, next to the button that caused it -- form actions
  // sit below-right and row actions are right-aligned in the last column -- and
  // clear of the topbar's controls, which the old top-right corner sat on top
  // of. Gone in four seconds; the page behind it already shows the result.
  window.lmsToast = function (icon, title) {
    Swal.fire({
      toast: true, position: 'bottom-end', showConfirmButton: false,
      timer: 3800, timerProgressBar: true, icon: icon, title: title,
      customClass: { popup: 'lms-toast lms-toast-' + icon }
    });
  };

  // Something that needs a person. Top-centre, where nothing else in this
  // application ever appears, so the position itself means "attend to this" --
  // it stays rare by only ever carrying an intrusion or a refusal.
  //
  // Deliberately not SweetAlert2: it shows one thing at a time, so a routine
  // "User updated." would close an intrusion alert that had not been read.
  // These stack, and stay until dismissed.
  window.lmsAlert = function (tone, title, detail, link) {
    var host = document.getElementById('lms-alerts');
    if (!host) {
      host = document.createElement('div');
      host.id = 'lms-alerts';
      document.body.appendChild(host);
    }

    var alert = document.createElement('div');
    alert.className = 'lms-alert lms-alert-' + (tone || 'high');
    alert.setAttribute('role', 'alert');
    // Assertive: an attack in progress is worth interrupting a screen reader.
    alert.setAttribute('aria-live', 'assertive');

    var body = document.createElement('div');
    body.className = 'lms-alert-body';

    var heading = document.createElement('p');
    heading.className = 'lms-alert-title';
    heading.textContent = title;
    body.appendChild(heading);

    if (detail) {
      var line = document.createElement('p');
      line.className = 'lms-alert-detail';
      line.textContent = detail;
      body.appendChild(line);
    }

    // An address with no way to look at what it did is a dead end.
    if (link) {
      var a = document.createElement('a');
      a.className = 'lms-alert-link';
      a.href = link;
      a.textContent = 'View the events \u2192';
      body.appendChild(a);
    }

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'lms-alert-close';
    close.setAttribute('aria-label', 'Dismiss');
    close.innerHTML = '&times;';
    close.addEventListener('click', function () { alert.remove(); });

    alert.appendChild(body);
    alert.appendChild(close);
    host.appendChild(alert);
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
      if (flash.dataset.warning) lmsToast('warning', flash.dataset.warning);
      // A refused action is not a receipt: it says something did not happen,
      // and it used to disappear in under four seconds.
      if (flash.dataset.error) lmsAlert('high', 'That did not go through', flash.dataset.error);
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

    // Confirmation forms, and the page loader.
    //
    // Both are bound once on the document rather than on each form. A form
    // found at load and given its own listener stops being watched the moment
    // its row is replaced -- and the live filter replaces the whole list on
    // every search and every dropdown. The markup came back from the server
    // with data-confirm still on it and nothing listening, so Lift block,
    // Block again, Archive and the rest went through silently after any
    // filtering. Listening on the document covers rows that arrive later,
    // which is every row on a filtered list.
    document.addEventListener('submit', function (e) {
      var form = e.target.closest ? e.target.closest('form') : null;
      if (!form) return;

      if (form.hasAttribute('data-confirm')) {
        if (form.dataset.confirmed) return;
        e.preventDefault();
        ask(form);

        return;
      }

      // Another listener already handled it -- the list filters submit over
      // fetch and call preventDefault on the form itself, which runs before
      // this delegated one. Locking those would freeze a page that is not
      // going anywhere.
      if (e.defaultPrevented) return;

      // THE LOADING STATE. Marks the form busy, which greys its fields, blocks
      // pointer input and puts a spinner in the submit button (see the field
      // states in app.css). It is here for one reason: a second click on
      // Submit files a duplicate leave application, with its own reference
      // number, which somebody then cancels by hand.
      //
      // Native validation runs BEFORE this event, so a form the browser
      // refuses never reaches here and never locks.
      form.setAttribute('aria-busy', 'true');

      // A genuine navigation, not an AJAX form or one that stops to ask.
      if (!form.hasAttribute('data-no-loader')) {
        var loader = document.getElementById('page-loader');
        if (loader) loader.classList.add('active');
      }
    });

    // Coming back with the Back button restores the page from the bfcache
    // exactly as it was left -- mid-submit, with every field grey and the
    // button spinning for a request that finished long ago. Unlock it.
    window.addEventListener('pageshow', function (e) {
      if (!e.persisted) return;
      document.querySelectorAll('form[aria-busy="true"]').forEach(function (f) {
        f.removeAttribute('aria-busy');
      });
      var loader = document.getElementById('page-loader');
      if (loader) loader.classList.remove('active');
    });

    function ask(form) {
      var options = {
        text: form.dataset.confirm,
        tone: form.dataset.confirmTone || 'danger'
      };

      // Some decisions have to be written down as well as agreed to --
      // blocking an account is recorded against a reason. That belongs in the
      // question rather than in a second dialog after it: a browser prompt is
      // a stop that does not blur, does not follow the theme, and was
      // appearing BEFORE the confirmation, because an inline onsubmit is bound
      // before a listener added here is.
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
        // submit() does not fire the listener above again, but the flag stays
        // in case anything else submits the form the ordinary way.
        form.dataset.confirmed = '1';
        form.submit();
      });
    }

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
              if (bell.dataset.lastId) {
                // The severity is what the detector recorded, not a guess made
                // here -- so a rule graded critical later paints itself.
                lmsAlert(
                  data.latest.severity === 'medium' ? 'medium' : 'high',
                  'Intrusion alert',
                  data.latest.category + ' from ' + data.latest.ip,
                  bell.dataset.logUrl ? bell.dataset.logUrl + encodeURIComponent(data.latest.ip) : null
                );
              }
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

    // A row menu inside a scrollable table is cut off by it.
    //
    // .table-responsive is overflow-x:auto, and CSS forces the other axis to
    // auto the moment one is constrained -- so a dropdown that opens downward
    // is clipped at the container's edge, which on the user list left two of
    // its four items unreachable. The container lets the menu out while it is
    // open and goes back to scrolling when it closes.
    //
    // Delegated, so rows the live filter swaps in are covered too, and read
    // off Bootstrap's own events rather than a guess about which click opened
    // what.
    ['show.bs.dropdown', 'hide.bs.dropdown'].forEach(function (event) {
      document.addEventListener(event, function (e) {
        var scroller = e.target.closest && e.target.closest('.table-responsive');
        if (scroller) scroller.classList.toggle('menu-open', event === 'show.bs.dropdown');
      });
    });

    // A submit that cannot succeed, switched off until it can.
    //
    // The rule is in the controller: roles are required there and a submission
    // without one is rejected whatever the browser did. This is the affordance
    // in front of it, and the button is not rendered disabled in the markup --
    // with this script gone it stays pressable and the server explains itself,
    // which beats a dead button and no reason for it.
    document.querySelectorAll('form[data-requires-checked]').forEach(function (form) {
      var boxes = form.querySelectorAll(
        'input[type="checkbox"][name="' + form.dataset.requiresChecked + '"]'
      );
      var submit = form.querySelector('[type="submit"]');
      var hint = form.querySelector('[data-requires-hint]');
      if (!boxes.length || !submit) return;

      function sync() {
        var chosen = Array.prototype.some.call(boxes, function (box) { return box.checked; });
        submit.disabled = !chosen;
        if (hint) hint.hidden = chosen;
      }

      boxes.forEach(function (box) { box.addEventListener('change', sync); });
      sync();
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

