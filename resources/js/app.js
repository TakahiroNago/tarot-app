const questionForm = document.querySelector('.question-form');

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
		submitLabel.textContent = '問いを読み解いています...';
	});
}
