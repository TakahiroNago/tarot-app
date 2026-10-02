const questionForm = document.querySelector('.question-form');

if (questionForm) {
	const submitButton = questionForm.querySelector('button[type="submit"]');
	const submitLabel = questionForm.querySelector('.submit-label');

	questionForm.addEventListener('submit', () => {
		questionForm.classList.add('is-submitting');
		questionForm.setAttribute('aria-busy', 'true');
		submitButton.disabled = true;
		submitLabel.textContent = 'カードを広げています...';
	});
}
