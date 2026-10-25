document.addEventListener('DOMContentLoaded', function () {
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
