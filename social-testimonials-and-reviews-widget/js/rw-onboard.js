/**
 * Repuso plugin onboarding form handlers (vanilla JS).
 *
 * Signup card  → POST /v1/plugin/register (proxied through ajax_var.url → action=hook → path=plugin/register).
 *                Body is just { email, vanity_url, source }; no password.
 * Login card   → POST /v1/login (same proxy, path=login).
 *
 * On success, both flows stash the api_key/apikey via ajax action rw_store_login.
 * After that:
 *   - Signup shows an in-card "Account created" success view (the welcome
 *     email carries the generated password). The status poll in rw-admin.js
 *     picks up the new Connected state in the background.
 *   - Login triggers rw-admin.js's fast poll so the page flips to .rw-logged
 *     within seconds, no reload needed.
 *
 * Pre-fill values + URLs come from RepusoOnboard (wp_localize_script).
 */
(function () {
	'use strict';

	if (typeof RepusoOnboard === 'undefined') return;

	// Thin wrappers around window.rwT/rwTf (defined in rw-admin.js, which
	// loads before this file). Fall back to the English fallback when
	// rwT isn't on window for any reason, so a missing helper never
	// renders "undefined" in the UI.
	function T(key, fallback) {
		return (typeof window.rwT === 'function') ? window.rwT(key, fallback) : fallback;
	}
	function Tf(key, fallback) {
		if (typeof window.rwTf === 'function') {
			return window.rwTf.apply(null, arguments);
		}
		// Plain JS sprintf fallback so the page still renders if rwTf
		// is somehow missing (e.g. rw-admin.js failed to load).
		var args = Array.prototype.slice.call(arguments, 2);
		var i = 0;
		return String(fallback).replace(/%[sd]/g, function () {
			var v = args[i++];
			return v == null ? '' : String(v);
		});
	}

	var EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
	var RESERVED_TLDS = ['test', 'example', 'invalid', 'localhost', 'local'];
	var RESERVED_DOMAINS = ['localhost', 'example.com', 'example.org', 'example.net'];

	function isInvalidEmail(value) {
		var v = (value || '').trim().toLowerCase();
		if (!v) return T('email_required', 'Email is required.');
		if (!EMAIL_REGEX.test(v)) return T('email_invalid', 'Enter a valid email address.');
		var at = v.lastIndexOf('@');
		var domain = v.substring(at + 1);
		if (RESERVED_DOMAINS.indexOf(domain) !== -1) {
			return T('email_reserved_domain', 'Use a real email address, not a reserved placeholder domain.');
		}
		var lastDot = domain.lastIndexOf('.');
		var tld = lastDot === -1 ? '' : domain.substring(lastDot + 1);
		if (tld && RESERVED_TLDS.indexOf(tld) !== -1) {
			return T('email_reserved_tld', 'Use a real email address, not a local or reserved-TLD domain.');
		}
		return null;
	}

	function setStatus(node, message, type) {
		if (!node) return;
		node.textContent = message || '';
		node.className = 'rw-onboard__status is-visible rw-onboard__status-' + (type || 'success');
	}

	function hideStatus(node) {
		if (!node) return;
		node.className = 'rw-onboard__status';
		node.textContent = '';
	}

	function setButtonLoading(btn, isLoading, idleLabel, busyLabel) {
		if (!btn) return;
		var labelNode = btn.querySelector('[data-label]');
		if (isLoading) {
			btn.disabled = true;
			if (labelNode) labelNode.textContent = busyLabel;
			if (!btn.querySelector('.rw-onboard__spinner')) {
				var spinner = document.createElement('span');
				spinner.className = 'rw-onboard__spinner';
				btn.insertBefore(spinner, btn.firstChild);
			}
		} else {
			btn.disabled = false;
			if (labelNode) labelNode.textContent = idleLabel;
			var existing = btn.querySelector('.rw-onboard__spinner');
			if (existing) existing.remove();
		}
	}

	function attachInlineEmailValidation(emailInput, errorNode) {
		if (!emailInput || !errorNode) return;
		var debounce = null;
		emailInput.addEventListener('input', function () {
			if (debounce) clearTimeout(debounce);
			debounce = setTimeout(function () {
				var v = emailInput.value;
				if (!v) { errorNode.textContent = ''; return; }
				errorNode.textContent = isInvalidEmail(v) || '';
			}, 400);
		});
	}

	// Sends a request through the WP-side `hook` proxy that already handles
	// Basic-auth + relays to api.repuso.* on our behalf. Returns a Promise of
	// the parsed JSON response, or rejects with an Error whose .data holds the
	// server-supplied payload (used to render friendly errors).
	function apiHook(path, method, body) {
		var form = new URLSearchParams();
		form.append('action', 'hook');
		form.append('nonce', ajax_var.nonce);
		form.append('path', path);
		form.append('method', method);
		// The hook handler expects body fields as $_POST['body'][...], so
		// flatten the JSON-ish body into bracket-notation params.
		if (body && typeof body === 'object') {
			Object.keys(body).forEach(function (k) {
				form.append('body[' + k + ']', body[k] == null ? '' : String(body[k]));
			});
		}
		// Content-Type tells the hook handler this is a JSON body when relaying.
		form.append('headers[Content-Type]', 'application/json');

		return fetch(ajax_var.url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: form.toString()
		}).then(function (res) {
			return res.text().then(function (text) {
				var data = {};
				try { data = text ? JSON.parse(text) : {}; } catch (e) { /* non-JSON */ }
				if (res.status >= 200 && res.status < 300 && !data.error && data.success !== false) {
					return data;
				}
				var err = new Error(data.msg || data.message || 'Request failed');
				err.status = res.status;
				err.data = data;
				throw err;
			});
		});
	}

	// Persist the api key on the WP side so the plugin treats this admin as
	// Connected on the next status poll. Returns a Promise.
	function storeApiKey(key) {
		var form = new URLSearchParams();
		form.append('action', 'rw_store_login');
		form.append('nonce', ajax_var.nonce);
		form.append('key', key);
		return fetch(ajax_var.url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: form.toString()
		});
	}

	function prefillForms() {
		var signupEmail = document.getElementById('rw-signup-email');
		var loginEmail  = document.getElementById('rw-login-email');
		if (signupEmail && !signupEmail.value) signupEmail.value = RepusoOnboard.email || '';
		if (loginEmail  && !loginEmail.value)  loginEmail.value  = RepusoOnboard.email || '';

		// (Forgot-password is an inline flow now - clicks switch to the
		// email-code tab in initLogin() - and the signup-success view
		// auto-redirects to the WP dashboard instead of linking to the
		// Repuso web dashboard, so no dashboard deep-link wiring left.)
	}

	function initSignup() {
		var form = document.getElementById('rw-signup-form');
		if (!form) return;

		var emailInput = form.querySelector('[name="email"]');
		var emailError = form.querySelector('[data-error-for="email"]');
		var submitBtn  = form.querySelector('[data-action="submit-signup"]');
		var statusNode = form.querySelector('[data-status]');
		var formWrap   = document.getElementById('rw-signup-form-wrap');
		var success    = document.getElementById('rw-signup-success');

		attachInlineEmailValidation(emailInput, emailError);

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			hideStatus(statusNode);

			var email = (emailInput.value || '').trim();
			var problem = isInvalidEmail(email);
			if (problem) {
				emailError.textContent = problem;
				emailInput.focus();
				return;
			}
			emailError.textContent = '';

			var signupIdle    = T('create_account_btn',    'Create my Repuso account');
			var signupLoading = T('signup_button_loading', 'Creating your account…');
			setButtonLoading(submitBtn, true, signupIdle, signupLoading);

			apiHook('plugin/register', 'POST', {
				email: email,
				vanity_url: RepusoOnboard.vanityUrl,
				source: RepusoOnboard.source
			})
				.then(function (data) {
					if (!data.api_key) {
						throw Object.assign(new Error(data.msg || T('signup_failed_generic', 'We could not create your account. Try again.')), { data: data });
					}
					return storeApiKey(data.api_key).then(function () {
						// Swap the form for the "Account created" view and
						// then navigate to the WP plugin's Dashboard page,
						// where the user will see the Complete-your-setup
						// card with auto-login-wired CTAs. Small delay so
						// the success message is actually perceivable
						// (without it the page flips before the message
						// finishes painting on fast connections).
						if (formWrap) formWrap.style.display = 'none';
						if (success)  success.style.display  = '';
						setTimeout(function () {
							var url = (typeof ajax_var !== 'undefined' && ajax_var.dashboardUrl) ? ajax_var.dashboardUrl : '';
							if (url) window.location.href = url;
						}, 1200);
					});
				})
				.catch(function (err) {
					var data = err.data || {};
					if (data.exists) {
						setStatus(statusNode,
							T('signup_email_exists', 'An account with this email already exists. Use the "I already have a Repuso account" form to sign in.'),
							'error');
					} else {
						setStatus(statusNode, err.message || T('signup_failed_generic', 'We could not create your account. Try again.'), 'error');
					}
					setButtonLoading(submitBtn, false, signupIdle, signupLoading);
				});
		});
	}

	function initLogin() {
		var form = document.getElementById('rw-login-form');
		if (!form) return;

		var emailInput = form.querySelector('[name="email"]');
		var passInput  = form.querySelector('[name="password"]');
		var passError  = form.querySelector('[data-error-for="password"]');
		var codeInput  = form.querySelector('[name="code"]');
		var codeError  = form.querySelector('[data-error-for="code"]');
		var statusNode = form.querySelector('[data-status]');
		var sentHint   = form.querySelector('[data-code-sent-hint]');

		var submitBtn  = form.querySelector('[data-action="submit-login"]');
		var sendBtn    = form.querySelector('[data-action="send-login-code"]');
		var verifyBtn  = form.querySelector('[data-action="verify-login-code"]');
		var resendLink = form.querySelector('[data-action="resend-login-code"]');

		var steps = form.querySelectorAll('[data-login-step]');

		// Show one step at a time. The form has three steps under the
		// shared email input: password (default), code-request, and
		// code-verify. They stack in the same grid cell (.rw-login__steps)
		// so toggling the .is-current class swaps which one is shown
		// without changing the card height - that keeps the login card
		// aligned with the signup card next to it.
		function showStep(name) {
			for (var i = 0; i < steps.length; i++) {
				steps[i].classList.toggle('is-current', steps[i].getAttribute('data-login-step') === name);
			}
		}
		function currentStep() {
			for (var i = 0; i < steps.length; i++) {
				if (steps[i].classList.contains('is-current')) {
					return steps[i].getAttribute('data-login-step');
				}
			}
			return 'password';
		}

		// Bottom-of-form switch links (no more tab strip). Always reset
		// the inline status so an error from the prior mode doesn't
		// carry over into the new one and confuse the user.
		form.addEventListener('click', function (e) {
			var link = e.target && e.target.closest ? e.target.closest('[data-action]') : null;
			if (!link) return;
			var action = link.getAttribute('data-action');
			if (action === 'switch-to-code') {
				e.preventDefault();
				hideStatus(statusNode);
				showStep('code-request');
			} else if (action === 'switch-to-password') {
				e.preventDefault();
				hideStatus(statusNode);
				showStep('password');
			}
		});

		// ---- Password mode submit ----
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			// Decide which path to take based on which step is current.
			var step = currentStep();
			if (step === 'password') {
				submitPassword();
			} else if (step === 'code-verify') {
				submitCode();
			}
		});

		function submitPassword() {
			hideStatus(statusNode);
			var email = (emailInput.value || '').trim();
			var pass  = passInput.value || '';

			if (!email || !pass) {
				if (passError) passError.textContent = !pass ? T('login_password_required', 'Password is required.') : '';
				if (!email) emailInput.focus(); else passInput.focus();
				return;
			}
			if (passError) passError.textContent = '';

			var loginIdle    = T('sign_in_btn',          'Sign in');
			var loginLoading = T('login_button_loading', 'Signing in…');
			setButtonLoading(submitBtn, true, loginIdle, loginLoading);

			apiHook('login', 'POST', { email: email, password: pass })
				.then(function (data) {
					// /v1/login historically returns `apikey` (no underscore);
					// /v1/plugin/register returns `api_key`. Accept either.
					var key = data.apikey || data.api_key;
					if (!key) {
						throw Object.assign(new Error(data.msg || T('login_invalid', 'Invalid email or password')), { data: data });
					}
					return storeApiKey(key).then(function () {
						// Reset the button before the view flips so a future
						// disconnect that brings the onboard form back doesn't
						// re-reveal the stale "Signing in…" loading state.
						setButtonLoading(submitBtn, false, loginIdle, loginLoading);
						if (typeof window.rwStartPoll === 'function') window.rwStartPoll('fast');
						if (typeof window.rwCheckConnection === 'function') window.rwCheckConnection();
					});
				})
				.catch(function (err) {
					setStatus(statusNode, err.message || T('login_invalid', 'Invalid email or password'), 'error');
					setButtonLoading(submitBtn, false, loginIdle, loginLoading);
				});
		}

		// ---- Code mode: request a code ----
		function requestCode() {
			hideStatus(statusNode);
			var email = (emailInput.value || '').trim();
			var problem = isInvalidEmail(email);
			if (problem) {
				setStatus(statusNode, problem, 'error');
				emailInput.focus();
				return;
			}

			var codeIdle    = T('email_me_code_btn',    'Email me a code');
			var codeLoading = T('code_button_loading',  'Sending…');
			setButtonLoading(sendBtn, true, codeIdle, codeLoading);

			apiHook('login/code/send', 'POST', { email: email, lang: RepusoOnboard.lang || '' })
				.then(function () {
					setButtonLoading(sendBtn, false, codeIdle, codeLoading);
					if (sentHint) sentHint.textContent = Tf('code_sent_hint', 'We emailed a 6-digit code to %s. It expires in 10 minutes.', email);
					showStep('code-verify');
					if (codeInput) {
						codeInput.value = '';
						setTimeout(function () { codeInput.focus(); }, 0);
					}
				})
				.catch(function (err) {
					// The API returns success for unknown emails too (no
					// enumeration), so a real .catch here means a network
					// or 4xx error, not "email not found". Surface as-is.
					setButtonLoading(sendBtn, false, codeIdle, codeLoading);
					setStatus(statusNode, err.message || T('code_send_failed', "Couldn't send the code. Please try again."), 'error');
				});
		}
		if (sendBtn) sendBtn.addEventListener('click', requestCode);
		if (resendLink) {
			resendLink.addEventListener('click', function (e) {
				e.preventDefault();
				requestCode();
			});
		}

		// ---- Code mode: verify the code ----
		function submitCode() {
			hideStatus(statusNode);
			if (codeError) codeError.textContent = '';

			var email = (emailInput.value || '').trim();
			var code  = (codeInput.value || '').replace(/\D/g, '');

			if (code.length !== 6) {
				if (codeError) codeError.textContent = T('code_required', 'Enter the 6-digit code from your email.');
				if (codeInput) codeInput.focus();
				return;
			}

			var verifyIdle    = T('sign_in_with_code_btn', 'Sign in with code');
			var verifyLoading = T('login_button_loading',  'Signing in…');
			setButtonLoading(verifyBtn, true, verifyIdle, verifyLoading);

			apiHook('login/code/verify', 'POST', { email: email, code: code })
				.then(function (data) {
					var key = data.apikey || data.api_key;
					if (!key) {
						throw Object.assign(new Error(data.msg || T('code_invalid', 'Invalid or expired code. Try again or request a new one.')), { data: data });
					}
					return storeApiKey(key).then(function () {
						// Reset before the view flips so a future disconnect
						// that brings the onboard form back doesn't re-reveal
						// the stale "Signing in…" loading state.
						setButtonLoading(verifyBtn, false, verifyIdle, verifyLoading);
						if (typeof window.rwStartPoll === 'function') window.rwStartPoll('fast');
						if (typeof window.rwCheckConnection === 'function') window.rwCheckConnection();
					});
				})
				.catch(function (err) {
					setButtonLoading(verifyBtn, false, verifyIdle, verifyLoading);
					setStatus(statusNode, err.message || T('code_invalid', 'Invalid or expired code. Try again or request a new one.'), 'error');
				});
		}

		// Strip non-digits as the user types so paste-with-spaces /
		// "123 456" / formatting artifacts get cleaned up automatically.
		if (codeInput) {
			codeInput.addEventListener('input', function () {
				var clean = (codeInput.value || '').replace(/\D/g, '').slice(0, 6);
				if (clean !== codeInput.value) codeInput.value = clean;
			});
		}

		// Exposed for rw-admin.js to call on the Not Connected
		// transition: returns the login card to a pristine state so
		// nothing from the prior session lingers (button still in
		// "Signing in…", OTP step still showing, error text, etc.)
		// when the user disconnects after a successful login.
		window.rwResetOnboardLoginForm = function () {
			showStep('password');
			hideStatus(statusNode);
			if (codeInput)  codeInput.value = '';
			if (codeError)  codeError.textContent = '';
			if (passError)  passError.textContent = '';
			setButtonLoading(submitBtn, false, T('sign_in_btn',          'Sign in'),                T('login_button_loading', 'Signing in…'));
			setButtonLoading(sendBtn,   false, T('email_me_code_btn',    'Email me a code'),        T('code_button_loading',  'Sending…'));
			setButtonLoading(verifyBtn, false, T('sign_in_with_code_btn','Sign in with code'),      T('login_button_loading', 'Signing in…'));
		};
	}

	document.addEventListener('DOMContentLoaded', function () {
		prefillForms();
		initSignup();
		initLogin();
	});
}());
