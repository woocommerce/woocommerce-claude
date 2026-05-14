/**
 * Hey Woo setup page client-side wiring.
 *
 *  - Tab switcher for setup panels.
 *  - Copy-to-clipboard for setup snippets.
 */
(function () {
	'use strict';

	function initTabs() {
		document.querySelectorAll('.woocommerce-claude-setup__tabs').forEach(function (tablist) {
			var tabs = tablist.querySelectorAll('.woocommerce-claude-setup__tab');
			if (!tabs.length) {
				return;
			}

			var panels = Array.prototype.map.call(tabs, function (tab) {
				return document.getElementById(tab.getAttribute('aria-controls'));
			}).filter(Boolean);

			function activate(targetTab) {
				tabs.forEach(function (tab) {
					var isActive = tab === targetTab;
					tab.classList.toggle('is-active', isActive);
					tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
					tab.setAttribute('tabindex', isActive ? '0' : '-1');
				});
				panels.forEach(function (panel) {
					panel.hidden = panel.id !== targetTab.getAttribute('aria-controls');
				});
			}

			tabs.forEach(function (tab) {
				tab.addEventListener('click', function () {
					activate(tab);
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
					var nextTab = tablist.querySelector(
						'.woocommerce-claude-setup__tab[data-woocommerce-claude-tab="' + keys[nextIndex] + '"]'
					);
					if (nextTab) {
						activate(nextTab);
						nextTab.focus();
					}
				});
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
				} else if (key === 'agent-plugin-cli') {
					source = document.querySelector('[data-woocommerce-claude-agent-plugin-cli]');
				}
				if (source) {
					copyText(source.textContent, button);
				}
			});
		});
	}

	function initTelemetryToggle() {
		var checkbox = document.querySelector('[data-woocommerce-claude-telemetry-toggle]');
		if (!checkbox) {
			return;
		}
		checkbox.addEventListener('change', function (event) {
			var target = event.target;
			var url = target.checked ? target.dataset.enableUrl : target.dataset.disableUrl;
			if (url) {
				window.location.href = url;
			}
		});
		// Stop the "Learn more" link inside the label from also toggling
		// the checkbox — otherwise opening the WC tracking page in a new
		// tab would also flip the merchant's preference.
		var learn = document.querySelector('.woocommerce-claude-setup__optin-learn');
		if (learn) {
			learn.addEventListener('click', function (event) {
				event.stopPropagation();
			});
		}
	}

	function init() {
		initTabs();
		initCopy();
		initTelemetryToggle();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
