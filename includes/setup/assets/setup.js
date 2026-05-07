/**
 * Hey Woo setup page client-side wiring.
 *
 *  - Tab switcher for the Step 2 "Easy install" / "Manual setup" panels.
 *  - Copy-to-clipboard for the manual snippets.
 */
(function () {
	'use strict';

	function initTabs() {
		var tabs = document.querySelectorAll('.woocommerce-claude-setup__tab');
		if (!tabs.length) {
			return;
		}

		function activate(targetKey) {
			tabs.forEach(function (tab) {
				var isActive = tab.getAttribute('data-woocommerce-claude-tab') === targetKey;
				tab.classList.toggle('is-active', isActive);
				tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
				tab.setAttribute('tabindex', isActive ? '0' : '-1');
			});
			document.querySelectorAll('.woocommerce-claude-setup__tabpanel').forEach(function (panel) {
				var isActive = panel.id === 'woocommerce-claude-panel-' + targetKey;
				panel.hidden = !isActive;
			});
		}

		tabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				activate(tab.getAttribute('data-woocommerce-claude-tab'));
			});
			tab.addEventListener('keydown', function (event) {
				if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') {
					return;
				}
				event.preventDefault();
				var keys = Array.prototype.map.call(tabs, function (t) {
					return t.getAttribute('data-woocommerce-claude-tab');
				});
				var current = tab.getAttribute('data-woocommerce-claude-tab');
				var index = keys.indexOf(current);
				var nextIndex = event.key === 'ArrowRight'
					? (index + 1) % keys.length
					: (index - 1 + keys.length) % keys.length;
				activate(keys[nextIndex]);
				var nextTab = document.querySelector(
					'.woocommerce-claude-setup__tab[data-woocommerce-claude-tab="' + keys[nextIndex] + '"]'
				);
				if (nextTab) {
					nextTab.focus();
				}
			});
		});
	}

	function copyText(text, button) {
		if (!text) {
			return;
		}
		var done = function () {
			var originalLabel = button.getAttribute('aria-label');
			button.classList.add('is-copied');
			button.setAttribute('aria-label', button.dataset.copiedLabel || 'Copied');
			button.disabled = true;
			setTimeout(function () {
				button.classList.remove('is-copied');
				if (originalLabel) {
					button.setAttribute('aria-label', originalLabel);
				}
				button.disabled = false;
			}, 1500);
		};

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(done, function () {
				fallbackCopy(text);
				done();
			});
			return;
		}
		fallbackCopy(text);
		done();
	}

	function fallbackCopy(text) {
		var area = document.createElement('textarea');
		area.value = text;
		area.style.position = 'fixed';
		area.style.opacity = '0';
		document.body.appendChild(area);
		area.select();
		try {
			document.execCommand('copy');
		} catch (e) {
			/* swallow */
		}
		document.body.removeChild(area);
	}

	function initCopy() {
		document.querySelectorAll('.woocommerce-claude-setup__copy').forEach(function (button) {
			button.addEventListener('click', function () {
				var key = button.getAttribute('data-woocommerce-claude-copy-target');
				var source;
				if (key === 'cli') {
					source = document.querySelector('[data-woocommerce-claude-cli]');
				} else if (key === 'json') {
					source = document.querySelector('[data-woocommerce-claude-json]');
				}
				if (source) {
					copyText(source.textContent, button);
				}
			});
		});
	}

	function init() {
		initTabs();
		initCopy();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
