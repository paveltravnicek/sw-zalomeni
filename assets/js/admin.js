document.addEventListener('DOMContentLoaded', function () {
	document.querySelectorAll('.swz-toggle input[type="checkbox"]').forEach(function (checkbox) {
		checkbox.addEventListener('change', function () {
			var field = this.closest('.swz-field');
			if (!field) return;
			var next = field.nextElementSibling;
			if (!next || !next.classList.contains('swz-field--list')) return;
			var input = next.querySelector('input[type="text"]');
			if (!input) return;
			input.disabled = !this.checked;
		});
	});
});
