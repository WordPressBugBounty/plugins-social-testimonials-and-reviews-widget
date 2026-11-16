/* AI Visibility: first load of the cards, dismiss, copy, preview picker, free check form. No dependencies. */
(function () {
	'use strict';
	var cfg = window.rwAI || {};

	function post(data) {
		var fd = new FormData();
		Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
		fd.append('nonce', cfg.nonce || '');
		return fetch(cfg.url, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); });
	}

	// Plugin Dashboard: the stats above this card show only once the page knows the account has channels. Show the card
	// at the same time (or when the setup card shows, or after 4 seconds) so it doesn't sit at the top, then jump down.
	var wait = document.querySelector('.rw-ai-wait');
	if (wait) {
		var started = Date.now();
		var timer = setInterval(function () {
			var shown = function (sel) { var el = document.querySelector(sel); return el && el.offsetParent !== null; };
			if (shown('.rw-kpis') || shown('[data-setup-card]') || Date.now() - started > 4000) {
				wait.classList.remove('rw-ai-wait');
				clearInterval(timer);
			}
		}, 100);
	}

	// First view after the update: nothing saved yet, so fetch once in the background.
	document.querySelectorAll('[data-rw-ai-load]').forEach(function (el) {
		post({ action: 'rw_ai_card', where: el.getAttribute('data-rw-ai-load') }).then(function (r) {
			var html = r && r.success && r.data ? r.data.html : '';
			if (html) { el.outerHTML = html; return; }
			var box = el.closest('.postbox, .rw-ai-plugin');
			if (box) box.style.display = 'none';
		}).catch(function () { el.textContent = ''; });
	});

	document.addEventListener('click', function (e) {
		// AI Visibility app links: open them signed in as this site's Repuso user (the key is fetched on click)
		var app = e.target.closest('.rw-ai a[href*="/ai-visibility/app/"]');
		if (app && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
			e.preventDefault();
			var win = window.open('', '_blank'); // opened now, inside the click, so pop-up blockers allow it
			if (win) { win.opener = null; }
			var go = function (u) { if (win) { win.location.href = u; } else { window.location.href = u; } };
			post({ action: 'rw_ai_app_url', url: app.href }).then(function (r) {
				go(r && r.success && r.data ? r.data.url : app.href);
			}).catch(function () { go(app.href); });
			return;
		}
		var d = e.target.closest('.rw-ai-dismiss');
		if (d) {
			e.preventDefault();
			post({ action: 'rw_ai_dismiss', what: d.getAttribute('data-what') });
			var box = d.closest('.postbox, .rw-ai-plugin');
			if (box) box.style.display = 'none';
			return;
		}
		var c = e.target.closest('.rw-ai-copy');
		if (c) {
			e.preventDefault();
			var text = c.getAttribute('data-copy') || '';
			var done = function () {
				var old = c.getAttribute('data-label') || c.innerHTML;
				c.setAttribute('data-label', old);
				c.textContent = cfg.copied || 'Copied';
				setTimeout(function () { c.innerHTML = old; }, 1500);
			};
			if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, function () {});
		}
	});

	// Preview: pick another widget.
	document.querySelectorAll('.rw-ai-autosubmit').forEach(function (s) {
		s.addEventListener('change', function () { s.form.submit(); });
	});

	// Free check (not connected): a website typed in the name field goes as ?site=, not ?q=.
	var free = document.querySelector('.rw-ai-free');
	if (free) {
		free.addEventListener('submit', function () {
			var q = free.querySelector('[name=q]'), site = free.querySelector('[name=site]');
			var v = (q.value || '').trim();
			if (/^(https?:\/\/)?[^\s\/]+\.[a-z]{2,}(\/\S*)?$/i.test(v)) {
				site.value = v.replace(/^https?:\/\//i, '').replace(/\/.*$/, '');
				q.disabled = true; // no ?q=: the landing page finds the listing by website
				setTimeout(function () { q.disabled = false; }, 0);
			}
		});
	}
})();
