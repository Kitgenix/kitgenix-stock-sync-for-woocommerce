/* Kitgenix Admin UI – Shared page-content component controller.
   Keep this file identical between plugins to ensure consistent UI/UX.
   Handles: modal open/close, collapsible cards, copy-to-clipboard,
   live table search/filter, and the toast/inline-save-confirmation helper.
   Does NOT touch kitgenix-admin-tabs.js – this is a separate, additive file.
*/

(function () {
  'use strict';

  window.KitgenixAdminUI = window.KitgenixAdminUI || {};

  function toArray(list) {
    return Array.prototype.slice.call(list || []);
  }

  /* ─────────────────────────────────────
     Toast / inline save confirmation
  ───────────────────────────────────── */

  var TOAST_REGION_ID = 'kitgenix-toast-region';
  var TOAST_TYPES = ['success', 'error', 'warning', 'info'];
  var TOAST_AUTO_DISMISS_MS = 3500;
  var TOAST_REMOVE_DELAY_MS = 200;

  function ensureToastRegion() {
    try {
      var region = document.getElementById(TOAST_REGION_ID);
      if (region) return region;
      if (!document.body) return null;
      region = document.createElement('div');
      region.id = TOAST_REGION_ID;
      region.className = 'kitgenix-toast-region';
      region.setAttribute('aria-live', 'polite');
      region.setAttribute('aria-atomic', 'true');
      document.body.appendChild(region);
      return region;
    } catch (_e) {
      return null;
    }
  }

  function toast(message, type) {
    try {
      var region = ensureToastRegion();
      if (!region) return;

      var resolvedType = TOAST_TYPES.indexOf(type) !== -1 ? type : 'info';

      var el = document.createElement('div');
      el.className = 'kitgenix-toast kitgenix-toast-' + resolvedType;
      el.setAttribute('role', resolvedType === 'error' ? 'alert' : 'status');
      el.textContent = message === null || message === undefined ? '' : String(message);
      region.appendChild(el);

      // Force a reflow so the visible-state transition actually animates in.
      void el.offsetWidth;
      el.classList.add('kitgenix-toast-visible');

      var removed = false;
      function remove() {
        if (removed) return;
        removed = true;
        try { el.classList.remove('kitgenix-toast-visible'); } catch (_e2) {}
        setTimeout(function () {
          try {
            if (el.parentNode) el.parentNode.removeChild(el);
          } catch (_e3) {}
        }, TOAST_REMOVE_DELAY_MS);
      }

      try { el.addEventListener('click', remove); } catch (_e4) {}
      setTimeout(remove, TOAST_AUTO_DISMISS_MS);
    } catch (_e) {}
  }

  window.KitgenixAdminUI.toast = toast;

  /* ─────────────────────────────────────
     Modal / dialog
  ───────────────────────────────────── */

  var openModalStack = [];

  function findModal(selector) {
    if (!selector) return null;
    try {
      return document.querySelector(selector);
    } catch (_e) {
      return null;
    }
  }

  function getFocusable(container) {
    if (!container) return null;
    try {
      return container.querySelector(
        'input:not([disabled]), textarea:not([disabled]), select:not([disabled]), button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'
      );
    } catch (_e) {
      return null;
    }
  }

  function openModal(modal, trigger) {
    if (!modal) return;
    try {
      modal.removeAttribute('hidden');
      modal.setAttribute('aria-hidden', 'false');
      modal.classList.add('kitgenix-modal-open');

      if (openModalStack.indexOf(modal) === -1) {
        openModalStack.push(modal);
      }

      if (openModalStack.length === 1) {
        try { document.documentElement.style.overflow = 'hidden'; } catch (_e5) {}
      }

      modal.__kitgenixReturnFocus = (trigger && trigger.nodeType === 1) ? trigger : (document.activeElement || null);

      var dialog = modal.querySelector('.kitgenix-modal-dialog');
      var autoFocusEl = null;
      try { autoFocusEl = modal.querySelector('[autofocus]'); } catch (_e6) {}
      var focusTarget = autoFocusEl || getFocusable(dialog) || dialog || modal;

      if (focusTarget && typeof focusTarget.focus === 'function') {
        setTimeout(function () {
          try { focusTarget.focus(); } catch (_e7) {}
        }, 10);
      }
    } catch (_e) {}
  }

  function closeModal(modal) {
    if (!modal) return;
    try {
      modal.setAttribute('hidden', 'hidden');
      modal.setAttribute('aria-hidden', 'true');
      modal.classList.remove('kitgenix-modal-open');

      var idx = openModalStack.indexOf(modal);
      if (idx !== -1) openModalStack.splice(idx, 1);

      if (!openModalStack.length) {
        try { document.documentElement.style.overflow = ''; } catch (_e8) {}
      }

      var returnFocus = modal.__kitgenixReturnFocus;
      modal.__kitgenixReturnFocus = null;
      if (returnFocus && typeof returnFocus.focus === 'function') {
        try { returnFocus.focus(); } catch (_e9) {}
      }
    } catch (_e) {}
  }

  function closeTopModal() {
    if (!openModalStack.length) return;
    closeModal(openModalStack[openModalStack.length - 1]);
  }

  /* ─────────────────────────────────────
     Collapsible / accordion card
  ───────────────────────────────────── */

  function toggleCollapsible(wrapper) {
    if (!wrapper) return;
    try {
      var trigger = wrapper.querySelector('.kitgenix-collapsible-trigger');
      var panel = wrapper.querySelector('.kitgenix-collapsible-panel');
      if (!trigger || !panel) return;

      var willOpen = panel.hasAttribute('hidden');
      if (willOpen) {
        panel.removeAttribute('hidden');
      } else {
        panel.setAttribute('hidden', 'hidden');
      }
      trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
      wrapper.classList.toggle('kitgenix-collapsible-open', willOpen);
    } catch (_e) {}
  }

  function initCollapsibleStates(root) {
    try {
      var scope = root || document;
      var wrappers = toArray(scope.querySelectorAll('[data-kitgenix-collapsible]'));
      wrappers.forEach(function (wrapper) {
        try {
          var trigger = wrapper.querySelector('.kitgenix-collapsible-trigger');
          var panel = wrapper.querySelector('.kitgenix-collapsible-panel');
          if (!trigger || !panel) return;
          var isOpen = !panel.hasAttribute('hidden');
          trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
          wrapper.classList.toggle('kitgenix-collapsible-open', isOpen);
        } catch (_eInner) {}
      });
    } catch (_e) {}
  }

  /* ─────────────────────────────────────
     Copy to clipboard
  ───────────────────────────────────── */

  function copyText(text) {
    try {
      if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        return navigator.clipboard.writeText(text);
      }
    } catch (_e) {}

    try {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.top = '-1000px';
      ta.style.left = '-1000px';
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
    } catch (_e2) {}

    return null;
  }

  function handleCopyClick(btn) {
    try {
      var selector = btn.getAttribute('data-kitgenix-copy');
      if (!selector) return;

      var source = null;
      try { source = document.querySelector(selector); } catch (_e) {}
      if (!source) return;

      var isFieldLike = source.tagName === 'INPUT' || source.tagName === 'TEXTAREA';
      var value = isFieldLike ? source.value : (source.textContent || '');

      var result = copyText(value);

      var markCopied = function () {
        try {
          if (btn.getAttribute('data-kitgenix-copy-label') === null) {
            btn.setAttribute('data-kitgenix-copy-label', btn.textContent || '');
          }
          var successLabel = btn.getAttribute('data-kitgenix-copy-success-label') || 'Copied!';
          btn.classList.add('kitgenix-copy-success');
          btn.textContent = successLabel;
          setTimeout(function () {
            try {
              btn.classList.remove('kitgenix-copy-success');
              btn.textContent = btn.getAttribute('data-kitgenix-copy-label') || successLabel;
            } catch (_eRevert) {}
          }, 1500);
        } catch (_eMark) {}
      };

      if (result && typeof result.then === 'function') {
        result.then(markCopied, markCopied);
      } else {
        markCopied();
      }
    } catch (_e) {}
  }

  /* ─────────────────────────────────────
     Live table search / filter
  ───────────────────────────────────── */

  function bindTableSearch(input) {
    try {
      if (!input || input.getAttribute('data-kitgenix-bound') === '1') return;
      input.setAttribute('data-kitgenix-bound', '1');

      var targetSelector = input.getAttribute('data-kitgenix-table-search-target');
      var container = null;
      if (targetSelector) {
        try { container = document.querySelector(targetSelector); } catch (_e) {}
      }
      if (!container) {
        container = (input.closest && input.closest('.kitgenix-table-wrap')) || document;
      }
      if (!container) return;

      function run() {
        try {
          var query = (input.value || '').trim().toLowerCase();
          var rows = toArray(container.querySelectorAll('[data-kitgenix-table-row]'));
          if (!rows.length) {
            rows = toArray(container.querySelectorAll('tbody tr'));
          }

          var visible = 0;
          rows.forEach(function (row) {
            var text = (row.textContent || '').toLowerCase();
            var match = !query || text.indexOf(query) !== -1;
            row.setAttribute('data-kitgenix-search-hidden', match ? '0' : '1');
            // Non-matches are always hidden immediately here. Matches, when
            // pagination owns this table, are left alone – pagination.reset()
            // below decides which of the matching rows fall on the current
            // page, so the two features don't fight over the same row.
            if (!match) {
              row.style.display = 'none';
            } else if (!container.kitgenixPaginate) {
              row.style.display = '';
            }
            if (match) visible++;
          });

          var emptyEl = container.querySelector('[data-kitgenix-table-empty]');
          if (emptyEl) {
            emptyEl.style.display = (query && visible === 0) ? '' : 'none';
          }

          if (container.kitgenixPaginate) {
            container.kitgenixPaginate.reset();
          }
        } catch (_eRun) {}
      }

      input.addEventListener('input', run);
      input.addEventListener('search', run);
    } catch (_e) {}
  }

  function initTableSearch(root) {
    try {
      var scope = root || document;
      var inputs = toArray(scope.querySelectorAll('[data-kitgenix-table-search]'));
      inputs.forEach(bindTableSearch);
    } catch (_e) {}
  }

  /* ─────────────────────────────────────
     Lightweight client-side table pagination
     Opt-in only: add data-kitgenix-table-paginate="25" (page size) to a
     .kitgenix-table-wrap. Operates purely on already-rendered rows – no
     query/data-model changes. Coordinates with live search above via
     data-kitgenix-search-hidden so the two never fight over row visibility.
  ───────────────────────────────────── */

  function bindTablePagination(wrap) {
    try {
      if (!wrap || wrap.getAttribute('data-kitgenix-paginate-bound') === '1') return;
      wrap.setAttribute('data-kitgenix-paginate-bound', '1');

      var pageSize = parseInt(wrap.getAttribute('data-kitgenix-table-paginate'), 10);
      if (!pageSize || pageSize < 1) pageSize = 25;

      var table = wrap.querySelector('table');
      if (!table) return;

      var pager = document.createElement('div');
      pager.className = 'kitgenix-pagination';
      pager.innerHTML =
        '<button type="button" class="button kitgenix-pagination-prev" aria-label="' + escAttr('Previous page') + '">&lsaquo;</button>' +
        '<span class="kitgenix-pagination-status" aria-live="polite"></span>' +
        '<button type="button" class="button kitgenix-pagination-next" aria-label="' + escAttr('Next page') + '">&rsaquo;</button>';
      wrap.parentNode.insertBefore(pager, wrap.nextSibling);

      var prevBtn = pager.querySelector('.kitgenix-pagination-prev');
      var nextBtn = pager.querySelector('.kitgenix-pagination-next');
      var statusEl = pager.querySelector('.kitgenix-pagination-status');
      var currentPage = 1;

      function escAttr(s) {
        return String(s).replace(/"/g, '&quot;');
      }

      function getRows() {
        var rows = toArray(table.querySelectorAll('[data-kitgenix-table-row]'));
        if (!rows.length) rows = toArray(table.querySelectorAll('tbody tr'));
        return rows.filter(function (row) {
          return row.getAttribute('data-kitgenix-search-hidden') !== '1';
        });
      }

      function render() {
        var rows = getRows();
        var total = rows.length;
        var totalPages = Math.max(1, Math.ceil(total / pageSize));
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;

        rows.forEach(function (row, idx) {
          var page = Math.floor(idx / pageSize) + 1;
          row.style.display = (page === currentPage) ? '' : 'none';
        });

        if (prevBtn) prevBtn.disabled = currentPage <= 1;
        if (nextBtn) nextBtn.disabled = currentPage >= totalPages;
        pager.style.display = (total <= pageSize) ? 'none' : '';

        if (statusEl) {
          if (total === 0) {
            statusEl.textContent = '';
          } else {
            var start = ((currentPage - 1) * pageSize) + 1;
            var end = Math.min(currentPage * pageSize, total);
            statusEl.textContent = start + '–' + end + ' / ' + total;
          }
        }
      }

      wrap.kitgenixPaginate = {
        render: render,
        reset: function () { currentPage = 1; render(); }
      };

      if (prevBtn) prevBtn.addEventListener('click', function () { currentPage--; render(); });
      if (nextBtn) nextBtn.addEventListener('click', function () { currentPage++; render(); });

      render();
    } catch (_e) {}
  }

  function initTablePagination(root) {
    try {
      var scope = root || document;
      var wraps = toArray(scope.querySelectorAll('[data-kitgenix-table-paginate]'));
      wraps.forEach(bindTablePagination);
    } catch (_e) {}
  }

  /* ─────────────────────────────────────
     Global delegated events
     Delegation means modal triggers, collapsible triggers and copy buttons
     work automatically for markup injected later (AJAX, tab panels, etc.)
     without needing to re-bind anything.
  ───────────────────────────────────── */

  var delegatedBound = false;

  function bindDelegatedEvents() {
    if (delegatedBound) return;
    delegatedBound = true;

    try {
      document.addEventListener('click', function (e) {
        var target = e.target;
        if (!target || typeof target.closest !== 'function') return;

        var modalTrigger = target.closest('[data-kitgenix-modal-target]');
        if (modalTrigger) {
          var selector = modalTrigger.getAttribute('data-kitgenix-modal-target');
          var modal = findModal(selector);
          if (modal) {
            e.preventDefault();
            openModal(modal, modalTrigger);
            return;
          }
        }

        var modalCloser = target.closest('[data-kitgenix-modal-close]');
        if (modalCloser) {
          var modalToClose = modalCloser.closest('.kitgenix-modal');
          if (modalToClose) {
            e.preventDefault();
            closeModal(modalToClose);
            return;
          }
        }

        var collapsibleTrigger = target.closest('.kitgenix-collapsible-trigger');
        if (collapsibleTrigger) {
          var wrapper = collapsibleTrigger.closest('[data-kitgenix-collapsible]');
          if (wrapper) {
            e.preventDefault();
            toggleCollapsible(wrapper);
            return;
          }
        }

        var copyBtn = target.closest('[data-kitgenix-copy]');
        if (copyBtn) {
          e.preventDefault();
          handleCopyClick(copyBtn);
          return;
        }
      });
    } catch (_e) {}

    try {
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' || e.key === 'Esc') {
          closeTopModal();
        }
      });
    } catch (_e2) {}
  }

  /* ─────────────────────────────────────
     Boot / public API
     initComponents() is idempotent and safe to call again after injecting
     new markup (e.g. an AJAX-rendered table) – already-bound elements are
     skipped via the data-kitgenix-bound guard, delegated click/keydown
     listeners are only ever attached once.
  ───────────────────────────────────── */

  function initComponents(root) {
    try {
      initCollapsibleStates(root);
      initTablePagination(root);
      initTableSearch(root);
    } catch (_e) {}
  }

  function boot() {
    bindDelegatedEvents();
    initComponents(document);
  }

  window.KitgenixAdminUI.initComponents = initComponents;

  window.KitgenixAdminUI.openModal = function (selector) {
    var modal = findModal(selector);
    if (modal) openModal(modal, null);
  };

  window.KitgenixAdminUI.closeModal = function (selector) {
    var modal = findModal(selector);
    if (modal) closeModal(modal);
  };

  if (window.KitgenixAdminUI.componentsReady) {
    // Script included more than once (e.g. by two plugins on the same
    // screen) – just rescan for any not-yet-bound elements, don't
    // re-attach the document-level delegated listeners.
    initComponents(document);
  } else {
    window.KitgenixAdminUI.componentsReady = true;
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', boot);
    } else {
      boot();
    }
  }
})();
