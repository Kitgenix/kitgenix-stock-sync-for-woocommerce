/* Kitgenix Admin UI – Shared topbar, tabs & search controller.
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

  function initSearch(root) {
    if (!root) return;
    var searchInput = root.querySelector('.kitgenix-topbar-search-input, .kitgenix-header-search-input');
    var clearBtn = root.querySelector('.kitgenix-search-clear, .kitgenix-header-search-clear');
    if (!searchInput) return;

    function performSearch() {
      var query = (searchInput.value || '').trim().toLowerCase();
      if (clearBtn) {
        clearBtn.style.display = query.length > 0 ? 'inline-block' : 'none';
      }

      var activePanel = root.querySelector('[data-kitgenix-tab-panel]:not([hidden])');
      if (!activePanel) {
        activePanel = root.querySelector('.kitgenix-settings-content') || root;
      }

      var items = toArray(activePanel.querySelectorAll(
        '.form-table tr, .kitgenix-card, .kitgenix-form-group, .kitgenix-setting-row, .kitgenix-section, table.widefat tbody tr, .kitgenix-grid-card, .kitgenix-settings-card'
      ));

      if (!items.length) {
        items = toArray(activePanel.children);
      }

      if (!query) {
        items.forEach(function (item) {
          item.style.display = '';
        });
        var noRes = activePanel.querySelector('.kitgenix-search-no-results');
        if (noRes) noRes.remove();
        return;
      }

      var matchCount = 0;
      items.forEach(function (item) {
        var text = (item.textContent || '').toLowerCase();
        if (text.indexOf(query) !== -1) {
          item.style.display = '';
          matchCount++;
        } else {
          item.style.display = 'none';
        }
      });

      var existingNoRes = activePanel.querySelector('.kitgenix-search-no-results');
      if (matchCount === 0) {
        if (!existingNoRes) {
          var msg = document.createElement('div');
          msg.className = 'kitgenix-search-no-results notice notice-info inline';
          msg.style.margin = '16px 0';
          msg.innerHTML = '<p>No settings matching "<strong>' + query.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</strong>" in this section.</p>';
          activePanel.appendChild(msg);
        }
      } else if (existingNoRes) {
        existingNoRes.remove();
      }
    }

    searchInput.addEventListener('input', performSearch);
    searchInput.addEventListener('keyup', function (e) {
      if (e.key === 'Escape') {
        searchInput.value = '';
        performSearch();
        searchInput.blur();
      }
    });

    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        searchInput.value = '';
        performSearch();
        searchInput.focus();
      });
    }

    // Global keyboard shortcut: '/' or 'Cmd+K' / 'Ctrl+K' to focus search
    document.addEventListener('keydown', function (e) {
      var tag = (e.target && e.target.tagName ? e.target.tagName.toLowerCase() : '');
      var isInput = tag === 'input' || tag === 'textarea' || tag === 'select' || (e.target && e.target.isContentEditable);
      if (isInput) return;

      if (e.key === '/' || ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k')) {
        e.preventDefault();
        if (root.KitgenixOpenSearch) {
          root.KitgenixOpenSearch();
        } else {
          searchInput.focus();
        }
        searchInput.select();
      }
    });

    root.addEventListener('click', function (e) {
      var a = e.target && e.target.closest ? e.target.closest('.kitgenix-tab-trigger[data-kitgenix-tab]') : null;
      if (a && searchInput.value) {
        setTimeout(performSearch, 50);
      }
    });
  }

  function initMobileMenu(root) {
    var topbar = root.querySelector('.kitgenix-topbar');
    if (!topbar) return;
    var hamburger = topbar.querySelector('.kitgenix-topbar-hamburger');
    var closeBtn = topbar.querySelector('.kitgenix-topbar-close-btn');

    if (hamburger) {
      hamburger.addEventListener('click', function () {
        topbar.classList.toggle('kitgenix-menu-open');
      });
    }

    if (closeBtn) {
      closeBtn.addEventListener('click', function () {
        topbar.classList.remove('kitgenix-menu-open');
      });
    }
  }

  function initDropdowns(root) {
    var topbar = root.querySelector('.kitgenix-topbar');
    if (!topbar) return;

    function closeAll(except) {
      toArray(topbar.querySelectorAll('.kitgenix-menu-item.kitgenix-has-dropdown.kitgenix-dropdown-open')).forEach(function (li) {
        if (li === except) return;
        li.classList.remove('kitgenix-dropdown-open');
        var toggle = li.querySelector('.kitgenix-menu-toggle');
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
      });
    }

    topbar.addEventListener('click', function (e) {
      var toggle = e.target && e.target.closest ? e.target.closest('.kitgenix-menu-toggle') : null;
      if (toggle) {
        e.preventDefault();
        var li = toggle.closest('.kitgenix-menu-item');
        if (!li) return;
        var isOpen = li.classList.contains('kitgenix-dropdown-open');
        closeAll(isOpen ? null : li);
        li.classList.toggle('kitgenix-dropdown-open', !isOpen);
        toggle.setAttribute('aria-expanded', String(!isOpen));
        return;
      }
      if (!(e.target && e.target.closest && e.target.closest('.kitgenix-submenu'))) {
        closeAll(null);
      }
    });

    document.addEventListener('click', function (e) {
      if (!topbar.contains(e.target)) closeAll(null);
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeAll(null);
    });
  }

  function initSearchToggle(root) {
    var wrap = root.querySelector('.kitgenix-topbar-search');
    if (!wrap) return;
    var toggle = wrap.querySelector('.kitgenix-search-toggle');
    var input = wrap.querySelector('.kitgenix-topbar-search-input');
    if (!toggle || !input) return;

    function open() {
      wrap.classList.add('kitgenix-search-open');
      toggle.setAttribute('aria-expanded', 'true');
      setTimeout(function () { input.focus(); }, 10);
    }

    function close() {
      if (input.value) return;
      wrap.classList.remove('kitgenix-search-open');
      toggle.setAttribute('aria-expanded', 'false');
    }

    toggle.addEventListener('click', function (e) {
      e.preventDefault();
      if (wrap.classList.contains('kitgenix-search-open')) {
        wrap.classList.remove('kitgenix-search-open');
        toggle.setAttribute('aria-expanded', 'false');
      } else {
        open();
      }
    });

    input.addEventListener('keyup', function (e) {
      if (e.key === 'Escape') {
        input.value = '';
        wrap.classList.remove('kitgenix-search-open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.focus();
      }
    });

    document.addEventListener('click', function (e) {
      if (!wrap.contains(e.target)) close();
    });

    root.KitgenixOpenSearch = open;
  }

  function initThemeToggle(root) {
    var btn = root.querySelector('.kitgenix-theme-toggle');
    var app = root.classList && root.classList.contains('kitgenix-admin-app') ? root : ( root.closest ? root.closest('.kitgenix-admin-app') : null );
    if (!btn || !app) return;

    var STORAGE_KEY = 'kitgenixAdminTheme';

    function apply(theme) {
      app.setAttribute('data-kitgenix-theme', theme);
      btn.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
    }

    var saved = null;
    try { saved = window.localStorage.getItem(STORAGE_KEY); } catch (_e) {}
    var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    apply(saved === 'dark' || saved === 'light' ? saved : (prefersDark ? 'dark' : 'light'));

    btn.addEventListener('click', function () {
      var current = app.getAttribute('data-kitgenix-theme') === 'dark' ? 'dark' : 'light';
      var next = current === 'dark' ? 'light' : 'dark';
      apply(next);
      try { window.localStorage.setItem(STORAGE_KEY, next); } catch (_e) {}
    });
  }

  function initRoot(root) {
    if (!root) return;

    initSearch(root);
    initMobileMenu(root);
    initDropdowns(root);
    initSearchToggle(root);
    initThemeToggle(root);

    var triggers = toArray(root.querySelectorAll('.kitgenix-tab-trigger[data-kitgenix-tab]'));
    var panels = toArray(root.querySelectorAll('[data-kitgenix-tab-panel]'));
    var conditionalItems = toArray(root.querySelectorAll('[data-kitgenix-tab-hide-on], [data-kitgenix-tab-show-on]'));
    if (!triggers.length || !panels.length) return;

    function parseTabList(value) {
      return String(value || '')
        .split(',')
        .map(function (item) { return String(item || '').trim(); })
        .filter(Boolean);
    }

    function defaultTab() {
      return root.getAttribute('data-kitgenix-default-tab') || (triggers[0] ? triggers[0].getAttribute('data-kitgenix-tab') : '') || '';
    }

    function setActive(tab) {
      if (!tab) tab = defaultTab();

      // Collect the parent <li> of any active trigger first, then apply once at the
      // end. A dropdown's <li> can wrap several triggers (see .kitgenix-has-dropdown),
      // so toggling li active state trigger-by-trigger would let a later, inactive
      // sibling clobber an earlier, active one.
      var activeLis = [];

      triggers.forEach(function (a) {
        var t = a.getAttribute('data-kitgenix-tab') || '';
        var active = t === tab;
        a.classList.toggle('nav-tab-active', active);
        a.classList.toggle('kitgenix-active', active);
        if (active) a.setAttribute('aria-current', 'page');
        else a.removeAttribute('aria-current');
        if (active) {
          var li = a.closest('.kitgenix-menu-item');
          if (li && activeLis.indexOf(li) === -1) activeLis.push(li);
        }
      });

      toArray(root.querySelectorAll('.kitgenix-topbar-menu .kitgenix-menu-item')).forEach(function (li) {
        li.classList.toggle('kitgenix-active', activeLis.indexOf(li) !== -1);
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

      conditionalItems.forEach(function (item) {
        var hideOn = parseTabList(item.getAttribute('data-kitgenix-tab-hide-on'));
        var showOn = parseTabList(item.getAttribute('data-kitgenix-tab-show-on'));
        var show = true;

        if (showOn.length) {
          show = showOn.indexOf(tab) !== -1;
        }
        if (hideOn.length && hideOn.indexOf(tab) !== -1) {
          show = false;
        }

        if (show) {
          item.removeAttribute('hidden');
          item.style.display = '';
          item.setAttribute('aria-hidden', 'false');
        } else {
          item.setAttribute('hidden', 'hidden');
          item.style.display = 'none';
          item.setAttribute('aria-hidden', 'true');
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

      var topbar = root.querySelector('.kitgenix-topbar');
      if (topbar) {
        topbar.classList.remove('kitgenix-menu-open');
      }
    });

    window.addEventListener('hashchange', function () {
      setActive(getHashTab() || getQueryTab() || defaultTab());
    });

    root.addEventListener('submit', function () {
      syncWpReferers(root);
    }, true);

    setActive(getHashTab() || getQueryTab() || defaultTab());
  }

  function normalizeNotices() {
    try {
      var apps = toArray(document.querySelectorAll('.kitgenix-admin-app, [data-kitgenix-tabs]'));
      apps.forEach(function (app) {
        if (!app || !app.closest) return;
        var wrap = app.closest('.wrap');
        if (!wrap || !wrap.parentNode) return;

        var headers = toArray(app.querySelectorAll('.kitgenix-topbar, .kitgenix-header-bar, .kitgenix-settings-header, .kitgenix-analytics-header'));
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
      mo.observe(document.body, { childList: true, subtree: true });
    } catch (_e) {}
  }

  // Measures the sticky topbar's real rendered height (it can wrap to two
  // lines on narrower desktop widths) and exposes it as --kitgenix-sticky-offset
  // so the settings sidebar (see kitgenix-admin-ui.css) can stick flush beneath
  // it instead of relying on a guessed pixel offset.
  function initStickyOffset(root) {
    if (!root) return;
    var topbar = root.querySelector('.kitgenix-topbar');
    if (!topbar) return;

    function sync() {
      try {
        var top = parseFloat(getComputedStyle(topbar).top) || 0;
        var offset = Math.ceil(top + topbar.getBoundingClientRect().height + 16);
        root.style.setProperty('--kitgenix-sticky-offset', offset + 'px');
      } catch (_e) {}
    }

    sync();

    if (window.ResizeObserver) {
      try {
        new ResizeObserver(sync).observe(topbar);
      } catch (_e) {}
    }

    var resizeTimer = null;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(sync, 100);
    });
    window.addEventListener('load', sync);
  }

  function boot() {
    normalizeNotices();
    armNoticeObserver();
    var apps = toArray(document.querySelectorAll('.kitgenix-admin-app, [data-kitgenix-tabs]'));
    apps.forEach(initRoot);
    apps.forEach(initStickyOffset);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
