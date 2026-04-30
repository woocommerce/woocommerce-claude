/**
 * Hey Woo setup page — copy-to-clipboard + navigate-on-pick for the
 * client picker. The picker can't be a nested <form> (we render
 * inside WC's outer settings form), so on change we read the
 * destination URL from the selected option's data attribute and
 * navigate via location.href.
 */
(function () {
	'use strict';

	function copyText(text, button) {
		if (!text) {
			return;
		}
		var done = function () {
			var original = button.textContent;
			button.textContent = button.dataset.copiedLabel || 'Copied';
			button.disabled = true;
			setTimeout(function () {
				button.textContent = original;
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

	function init() {
		document.querySelectorAll('.hey-woo-setup__copy').forEach(function (button) {
			button.addEventListener('click', function () {
				var key = button.getAttribute('data-hey-woo-copy-target');
				var source;
				if (key === 'credential') {
					source = document.querySelector('[data-hey-woo-credential]');
				} else if (key === 'cli') {
					source = document.querySelector('[data-hey-woo-cli]');
				} else if (key === 'json') {
					source = document.querySelector('[data-hey-woo-json]');
				}
				if (source) {
					copyText(source.textContent, button);
				}
			});
		});

		var picker = document.querySelector('[data-hey-woo-client]');
		if (picker) {
			picker.addEventListener('change', function () {
				var option = picker.options[picker.selectedIndex];
				var url = option && option.getAttribute('data-hey-woo-client-url');
				if (url) {
					window.location.href = url;
				}
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
