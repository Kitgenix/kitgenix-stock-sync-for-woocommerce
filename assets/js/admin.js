/* Kitgenix Stock Sync for WooCommerce — Admin UI */

(function () {
	'use strict';

	function boot() {
		var ROOT = document.getElementById('kitgenix-stock-sync-for-woocommerce-admin-app');
		if (!ROOT) return;

		function getTriggers() {
			return Array.prototype.slice.call(
				ROOT.querySelectorAll('.kitgenix-tab-trigger[data-kitgenix-stock-sync-for-woocommerce-tab]')
			);
		}

		function getPanels() {
			return Array.prototype.slice.call(
				ROOT.querySelectorAll('[data-kitgenix-stock-sync-for-woocommerce-tab-panel]')
			);
		}

		function setActive(tab) {
			if (!tab) tab = 'status';

			var triggers = getTriggers();
			for (var i = 0; i < triggers.length; i++) {
				var t = triggers[i].getAttribute('data-kitgenix-stock-sync-for-woocommerce-tab');
				var isActive = t === tab;
				triggers[i].classList.toggle('nav-tab-active', isActive);
				if (isActive) {
					triggers[i].setAttribute('aria-current', 'page');
				} else {
					triggers[i].removeAttribute('aria-current');
				}
			}

			var panels = getPanels();
			for (var j = 0; j < panels.length; j++) {
				var pTab = panels[j].getAttribute('data-kitgenix-stock-sync-for-woocommerce-tab-panel');
				var show = pTab === tab;
				if (show) {
					panels[j].removeAttribute('hidden');
					panels[j].style.display = '';
				} else {
					panels[j].setAttribute('hidden', 'hidden');
					panels[j].style.display = 'none';
				}
			}
		}

		ROOT.addEventListener('click', function (e) {
			var a = e.target && e.target.closest ? e.target.closest('.kitgenix-nav-tabs .kitgenix-tab-trigger') : null;
			if (!a) return;

			var tab = a.getAttribute('data-kitgenix-stock-sync-for-woocommerce-tab') || '';
			if (!tab) return;

			e.preventDefault();
			setActive(tab);

			try {
				var u = new URL(a.href);
				window.history.replaceState({}, '', u.toString());
			} catch (_e) {}
		});

		var initial = '';
		try {
			var u0 = new URL(window.location.href);
			initial = u0.searchParams.get('tab') || '';
		} catch (_e2) {}

		setActive(initial || 'status');
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
