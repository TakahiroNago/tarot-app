const questionForm = document.querySelector('.question-form');
const historyTopicFilter = document.querySelector('[data-history-topic-filter]');
const plansPanel = document.querySelector('.plans-panel');
const accountMenu = document.querySelector('.account-menu');
const accountPopover = accountMenu?.querySelector('.account-popover');
const accountMenuTrigger = accountMenu?.querySelector('.account-menu-trigger');

historyTopicFilter?.addEventListener('change', () => {
	historyTopicFilter.form?.requestSubmit();
});

document.querySelectorAll('[data-local-time]').forEach((timeElement) => {
	const date = new Date(timeElement.dateTime);

	if (Number.isNaN(date.getTime())) {
		return;
	}

	const pad = (value) => String(value).padStart(2, '0');

	timeElement.textContent = `${date.getFullYear()}/${pad(date.getMonth() + 1)}/${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
});

if (plansPanel && accountMenu && accountPopover && accountMenu.classList.contains('is-open')) {
	const dismissAccountPopover = () => {
		accountMenu.classList.remove('is-open');
		accountMenu.classList.add('is-dismissed');
	};

	document.addEventListener('click', (event) => {
		if (!accountPopover.contains(event.target) && !accountMenuTrigger?.contains(event.target)) {
			dismissAccountPopover();
		}
	});

	window.addEventListener('scroll', dismissAccountPopover, { passive: true });
}

document.querySelectorAll('[data-auto-dismiss]').forEach((notice) => {
	window.setTimeout(() => {
		notice.classList.add('is-dismissing');
		window.setTimeout(() => notice.remove(), 250);
	}, 5000);
});

if (questionForm) {
	const submitButton = questionForm.querySelector('button[type="submit"]');
	const submitLabel = questionForm.querySelector('.submit-label');

	questionForm.addEventListener('submit', () => {
		questionForm.classList.add('is-submitting');
		questionForm.setAttribute('aria-busy', 'true');
		submitButton.disabled = true;
		submitLabel.textContent = questionForm.dataset.loadingLabel;
	});
}
