/**
 * Hey Woo setup page client-side wiring.
 *
 *  - Tab switcher for the Step 2 "Easy install" / "Manual setup" panels.
 *  - Generate button that reads the description + permissions form
 *    fields and navigates to the nonce-protected admin-post URL with
 *    those values appended as query args.
 *  - Inline "Read + Write" warning toggle on the permissions dropdown.
 *  - Copy-to-clipboard for the manual snippets.
 */
(function () {
	'use strict';

	function initTabs() {
		var tabs = document.querySelectorAll('.hey-woo-setup__tab');
		if (!tabs.length) {
			return;
		}

		function activate(targetKey) {
			tabs.forEach(function (tab) {
				var isActive = tab.getAttribute('data-hey-woo-tab') === targetKey;
				tab.classList.toggle('is-active', isActive);
				tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
				tab.setAttribute('tabindex', isActive ? '0' : '-1');
			});
			document.querySelectorAll('.hey-woo-setup__tabpanel').forEach(function (panel) {
				var isActive = panel.id === 'hey-woo-panel-' + targetKey;
				panel.hidden = !isActive;
			});
		}

		tabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				activate(tab.getAttribute('data-hey-woo-tab'));
			});
			tab.addEventListener('keydown', function (event) {
				if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') {
					return;
				}
				event.preventDefault();
				var keys = Array.prototype.map.call(tabs, function (t) {
					return t.getAttribute('data-hey-woo-tab');
				});
				var current = tab.getAttribute('data-hey-woo-tab');
				var index = keys.indexOf(current);
				var nextIndex = event.key === 'ArrowRight'
					? (index + 1) % keys.length
					: (index - 1 + keys.length) % keys.length;
				activate(keys[nextIndex]);
				var nextTab = document.querySelector(
					'.hey-woo-setup__tab[data-hey-woo-tab="' + keys[nextIndex] + '"]'
				);
				if (nextTab) {
					nextTab.focus();
				}
			});
		});
	}

	function initGenerate() {
		var button = document.querySelector('[data-hey-woo-generate]');
		if (!button) {
			return;
		}

		button.addEventListener('click', function () {
			if (button.disabled) {
				return;
			}
			var base = button.getAttribute('data-hey-woo-action-base') || '';
			if (!base) {
				return;
			}

			var descriptionInput = document.getElementById('hey-woo-description');
			var permissionsInput = document.getElementById('hey-woo-permissions');
			var description = descriptionInput ? descriptionInput.value.trim() : '';
			var permissions = permissionsInput ? permissionsInput.value : 'read';

			var separator = base.indexOf('?') >= 0 ? '&' : '?';
			var url = base + separator
				+ 'permissions=' + encodeURIComponent(permissions)
				+ '&description=' + encodeURIComponent(description);

			button.disabled = true;
			button.textContent = button.dataset.busyLabel || 'Generating…';
			window.location.href = url;
		});
	}

	function initWriteWarning() {
		var permissionsInput = document.getElementById('hey-woo-permissions');
		var warning = document.querySelector('[data-hey-woo-write-warning]');
		if (!permissionsInput || !warning) {
			return;
		}

		function update() {
			warning.hidden = permissionsInput.value !== 'read_write';
		}

		permissionsInput.addEventListener('change', update);
		update();
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
		document.querySelectorAll('.hey-woo-setup__copy').forEach(function (button) {
			button.addEventListener('click', function () {
				var key = button.getAttribute('data-hey-woo-copy-target');
				var source;
				if (key === 'cli') {
					source = document.querySelector('[data-hey-woo-cli]');
				} else if (key === 'json') {
					source = document.querySelector('[data-hey-woo-json]');
				}
				if (source) {
					copyText(source.textContent, button);
				}
			});
		});
	}

	function init() {
		initTabs();
		initGenerate();
		initWriteWarning();
		initCopy();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
