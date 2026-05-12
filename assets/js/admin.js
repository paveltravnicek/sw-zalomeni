document.addEventListener('DOMContentLoaded', function () {

	// ── Přesun WP systémových notices pod hero ──────────────────
	var noticesArea = document.querySelector('.swz-notices-area');
	if (noticesArea) {
		var wrap = document.querySelector('.sw-zalomeni-admin');
		if (wrap && wrap.parentNode) {
			var toMove = [];
			wrap.parentNode.querySelectorAll('.notice, div.updated, div.error').forEach(function (el) {
				if (!wrap.contains(el)) toMove.push(el);
			});
			toMove.forEach(function (el) {
				noticesArea.insertBefore(el, noticesArea.firstChild);
			});
		}
	}

	// ── Toggle: enable/disable navazující list input ────────────
	document.querySelectorAll('.swz-toggle-wrap input[type="checkbox"]').forEach(function (checkbox) {
		checkbox.addEventListener('change', function () {
			var field = this.closest('.swz-field');
			if (!field) return;
			var next = field.nextElementSibling;
			if (!next || !next.classList.contains('swz-field--list')) return;
			var input = next.querySelector('input[type="text"]');
			if (!input) return;
			input.disabled = !this.checked;
			input.style.opacity = this.checked ? '' : '0.4';
		});
	});

	document.querySelectorAll('.swz-field--list input[type="text"][disabled]').forEach(function (input) {
		input.style.opacity = '0.4';
	});

	// ── Live preview ────────────────────────────────────────────
	var previewInput  = document.getElementById('swz-preview-input');
	var previewOutput = document.getElementById('swz-preview-output');
	var previewStatus = document.getElementById('swz-preview-status');

	if (!previewInput || !previewOutput) return;

	var debounceTimer = null;
	var lastText = '';

	function highlightNbsp(html) {
		// Zvýraznit &nbsp; jako viditelnou tečku
		return html.replace(/&nbsp;/g, '<mark class="swz-nbsp" title="pevná mezera">&middot;</mark>');
	}

	function runPreview(text) {
		if (text === lastText) return;
		lastText = text;

		if (!text.trim()) {
			previewOutput.innerHTML = '<span class="swz-preview-placeholder">Výsledek se zobrazí zde…</span>';
			if (previewStatus) previewStatus.textContent = '';
			return;
		}

		if (previewStatus) {
			previewStatus.textContent = 'Zpracovávám…';
			previewStatus.className = 'swz-preview-status swz-preview-status--loading';
		}

		var data = new FormData();
		data.append('action', 'sw_zalomeni_preview');
		data.append('nonce', swzAdmin.nonce);
		data.append('text', text);

		fetch(swzAdmin.ajaxUrl, { method: 'POST', body: data })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (!json.success) throw new Error('server error');
				var result = json.data.result;
				previewOutput.innerHTML = highlightNbsp(escapeHtml(result));
				if (previewStatus) {
					var changed = result !== json.data.original;
					previewStatus.textContent = changed ? 'Plugin provedl úpravy.' : 'Žádné změny — text vyhovuje pravidlům.';
					previewStatus.className = 'swz-preview-status ' + (changed ? 'swz-preview-status--changed' : 'swz-preview-status--ok');
				}
			})
			.catch(function () {
				previewOutput.innerHTML = '<span class="swz-preview-placeholder">Chyba při načítání náhledu.</span>';
				if (previewStatus) {
					previewStatus.textContent = '';
					previewStatus.className = 'swz-preview-status';
				}
			});
	}

	function escapeHtml(str) {
		return str
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	previewInput.addEventListener('input', function () {
		clearTimeout(debounceTimer);
		debounceTimer = setTimeout(function () {
			runPreview(previewInput.value);
		}, 420);
	});

});
