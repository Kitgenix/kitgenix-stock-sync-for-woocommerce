/* Kitgenix Admin UI — Shared tabs controller.
   Keep this file identical between plugins to ensure consistent UI/UX.
*/

(function () {
  'use strict';

  window.KitgenixAdminUI = window.KitgenixAdminUI || {};
  if (window.KitgenixAdminUI.tabsReady) return;
  window.KitgenixAdminUI.tabsReady = true;

  function toArray(list) {
    return Array.prototype.slice.call(list || []);
  }

  function getHashTab() {
    var h = window.location && window.location.hash ? String(window.location.hash) : '';
    var m = h.match(/^#kitgenix-tab-([a-z0-9\-]+)$/i);
    return m && m[1] ? m[1] : '';
  }

  function getQueryTab() {
    try {
      return (new URL(window.location.href)).searchParams.get('tab') || '';
    } catch (_e) {
      return '';
    }
  }

  function syncWpReferers(root) {
    try {
      var forms = root.querySelectorAll('form[action="options.php"]');
      if (!forms || !forms.length) return;
      var hash = window.location && window.location.hash ? String(window.location.hash) : '';
      toArray(forms).forEach(function (form) {
        var ref = form.querySelector('input[name="_wp_http_referer"]');
        if (!ref) return;
        var base = String(ref.value || '');
        if (!base) {
          base = (window.location && window.location.pathname ? window.location.pathname : '') +
            (window.location && window.location.search ? window.location.search : '');
        }
        base = base.split('#')[0];
        ref.value = base + hash;
      });
    } catch (_e2) {}
  }

  function setUrl(tab) {
    if (!tab) return;
    try {
      var url = new URL(window.location.href);
      url.searchParams.set('tab', tab);
      url.hash = 'kitgenix-tab-' + tab;
      window.history.replaceState({}, '', url.toString());
    } catch (_e) {
      try {
        window.location.hash = 'kitgenix-tab-' + tab;
      } catch (_e2) {}
    }
  }

  function initRoot(root) {
    if (!root) return;

    var triggers = toArray(root.querySelectorAll('.kitgenix-tab-trigger[data-kitgenix-tab]'));
    var panels = toArray(root.querySelectorAll('[data-kitgenix-tab-panel]'));
    if (!triggers.length || !panels.length) return;

    function defaultTab() {
      return root.getAttribute('data-kitgenix-default-tab') || (triggers[0] ? triggers[0].getAttribute('data-kitgenix-tab') : '') || '';
    }

    function setActive(tab) {
      if (!tab) tab = defaultTab();

      triggers.forEach(function (a) {
        var t = a.getAttribute('data-kitgenix-tab') || '';
        var active = t === tab;
        a.classList.toggle('nav-tab-active', active);
        if (active) a.setAttribute('aria-current', 'page');
        else a.removeAttribute('aria-current');
      });

      panels.forEach(function (p) {
        var t = p.getAttribute('data-kitgenix-tab-panel') || '';
        var show = t === tab;
        if (show) {
          p.removeAttribute('hidden');
          p.style.display = '';
          p.setAttribute('aria-hidden', 'false');
        } else {
          p.setAttribute('hidden', 'hidden');
          p.style.display = 'none';
          p.setAttribute('aria-hidden', 'true');
        }
      });

      syncWpReferers(root);
    }

    root.addEventListener('click', function (e) {
      var a = e.target && e.target.closest ? e.target.closest('.kitgenix-tab-trigger[data-kitgenix-tab]') : null;
      if (!a) return;
      var tab = a.getAttribute('data-kitgenix-tab') || '';
      if (!tab) return;
      e.preventDefault();
      setActive(tab);
      setUrl(tab);
    });

    window.addEventListener('hashchange', function () {
      setActive(getHashTab() || getQueryTab() || defaultTab());
    });

    root.addEventListener('submit', function () {
      syncWpReferers(root);
    }, true);

    setActive(getHashTab() || getQueryTab() || defaultTab());
  }

  // Some environments/plugins relocate admin notices into custom headers.
  // Normalize by moving any `.notice` nodes found inside Kitgenix header blocks
  // back into the standard WP notice area (immediately before the `.wrap`).
  function normalizeNotices() {
    try {
      var apps = toArray(document.querySelectorAll('.kitgenix-admin-app, [data-kitgenix-tabs]'));
      apps.forEach(function (app) {
        if (!app || !app.closest) return;
        var wrap = app.closest('.wrap');
        if (!wrap || !wrap.parentNode) return;

        var headers = toArray(app.querySelectorAll('.kitgenix-settings-header, .kitgenix-analytics-header'));
        headers.forEach(function (header) {
          if (!header) return;
          var notices = toArray(header.querySelectorAll('.notice, .settings-error'));
          if (!notices.length) return;

          for (var i = notices.length - 1; i >= 0; i--) {
            var n = notices[i];
            if (!n || n.nodeType !== 1) continue;
            if (n.getAttribute('data-kitgenix-notice-normalized') === '1') continue;
            n.setAttribute('data-kitgenix-notice-normalized', '1');
            wrap.parentNode.insertBefore(n, wrap);
          }
        });
      });
    } catch (_e) {}
  }

  function armNoticeObserver() {
    try {
      if (!window.MutationObserver) return;
      var mo = new MutationObserver(function (mutations) {
        var hit = false;
        for (var i = 0; i < mutations.length; i++) {
          var m = mutations[i];
          if (!m || !m.addedNodes || !m.addedNodes.length) continue;
          for (var j = 0; j < m.addedNodes.length; j++) {
            var node = m.addedNodes[j];
            if (!node || node.nodeType !== 1) continue;
            if (node.classList && (node.classList.contains('notice') || node.classList.contains('settings-error'))) { hit = true; break; }
            if (node.querySelector && node.querySelector('.notice, .settings-error')) { hit = true; break; }
          }
          if (hit) break;
        }
        if (hit) normalizeNotices();
      });
      mo.observe(document.documentElement || document.body, { childList: true, subtree: true });
      setTimeout(function () { try { mo.disconnect(); } catch (_e) {} }, 3000);
    } catch (_e2) {}
  }

  function boot() {
    var roots = toArray(document.querySelectorAll('[data-kitgenix-tabs]'));
    roots.forEach(initRoot);
    normalizeNotices();
    // Re-run shortly after load in case other scripts move notices.
    setTimeout(normalizeNotices, 50);
    setTimeout(normalizeNotices, 250);
    armNoticeObserver();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
