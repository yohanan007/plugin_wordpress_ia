document.addEventListener('DOMContentLoaded', function () {
	// Compteur de tokens des consignes : même estimation que le serveur
	// (TokenEstimator : caractères / 4, arrondi au supérieur).
	const prompt = document.getElementById('wpia-system-prompt');
	const counter = document.getElementById('wpia-system-prompt-counter');
	if (prompt && counter) {
		const max = parseInt(prompt.dataset.maxTokens || '0', 10);
		const initial = counter.textContent;
		const update = function () {
			const tokens = Math.ceil(Array.from(prompt.value.trim()).length / 4);
			counter.textContent = tokens + ' / ' + max + ' tokens (estimation)';
			counter.style.color = tokens > max ? '#b32d2e' : '';
		};
		prompt.addEventListener('input', update);
		if (prompt.value !== '') {
			update();
		} else {
			counter.textContent = initial;
		}
	}

	const provider = document.getElementById('wpia-provider');
	const button = document.getElementById('wpia-load-provider-defaults');
	const url = document.getElementById('wpia-provider-url');
	const model = document.getElementById('wpia-model');

	if (!provider || !button || !url || !model) {
		return;
	}

	let previous = provider.options[provider.selectedIndex];

	provider.addEventListener('change', function () {
		const selected = provider.options[provider.selectedIndex];
		if (url.value === (previous.dataset.defaultUrl || '')) {
			url.value = selected.dataset.defaultUrl || '';
		}
		if (model.value === (previous.dataset.defaultModel || '')) {
			model.value = selected.dataset.defaultModel || '';
		}
		previous = selected;
	});

	button.addEventListener('click', function () {
		const selected = provider.options[provider.selectedIndex];
		url.value = selected.dataset.defaultUrl || '';
		model.value = selected.dataset.defaultModel || '';
	});
});
