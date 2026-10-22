const questionForm = document.querySelector('.question-form:not([data-account-settings-form], [data-delete-account-form])');
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

const scheduleAutoDismiss = (notice) => {
	window.setTimeout(() => {
		notice.classList.add('is-dismissing');
		window.setTimeout(() => notice.remove(), 250);
	}, 5000);
};

const toastRegions = [...document.querySelectorAll('[data-toast-region]')];
const toastRegion = toastRegions.find((region) => region.hasAttribute('data-account-settings-notice'))
	?? toastRegions[0]
	?? document.createElement('div');

if (toastRegions.length === 0) {
	toastRegion.className = 'toast-region';
	toastRegion.setAttribute('data-toast-region', '');
	toastRegion.setAttribute('aria-live', 'polite');
	document.body.append(toastRegion);
} else {
	toastRegions.filter((region) => region !== toastRegion).forEach((region) => {
		while (region.firstChild) {
			toastRegion.append(region.firstChild);
		}

		region.remove();
	});
}

window.showToast = (message, isError = false) => {
	const toast = document.createElement('p');
	toast.className = isError ? 'toast is-error' : 'toast';
	toast.setAttribute('data-auto-dismiss', '');
	toast.setAttribute('role', isError ? 'alert' : 'status');
	toast.textContent = message;
	toastRegion.append(toast);
	scheduleAutoDismiss(toast);
};

document.querySelectorAll('[data-auto-dismiss]').forEach(scheduleAutoDismiss);

const accountSettingsNotice = document.querySelector('[data-account-settings-notice]');

if (accountSettingsNotice) {
	const showNotice = (message, isError = false) => {
		window.showToast(message, isError);
	};

	document.querySelectorAll('[data-account-settings-form]').forEach((form) => {
		form.addEventListener('submit', async (event) => {
			event.preventDefault();

			const submitButton = form.querySelector('button[type="submit"]');
			submitButton.disabled = true;
			form.setAttribute('aria-busy', 'true');

			try {
				const response = await fetch(form.action, {
					method: 'POST',
					body: new FormData(form),
					headers: {
						Accept: 'application/json',
						'X-Requested-With': 'XMLHttpRequest',
					},
					credentials: 'same-origin',
				});
				let data;

				try {
					data = await response.json();
				} catch {
					throw new Error(accountSettingsNotice.dataset.errorMessage);
				}

				if (!response.ok) {
					const firstValidationError = Object.values(data.errors ?? {}).flat()[0];
					throw new Error(firstValidationError ?? data.message ?? accountSettingsNotice.dataset.errorMessage);
				}

				showNotice(data.message);
			} catch (error) {
				showNotice(
					error instanceof Error ? error.message : accountSettingsNotice.dataset.errorMessage,
					true,
				);
			} finally {
				submitButton.disabled = false;
				form.removeAttribute('aria-busy');
			}
		});
	});

	const deleteAccountForm = document.querySelector('[data-delete-account-form]');
	const deleteAccountDialog = document.querySelector('[data-delete-account-dialog]');

	if (deleteAccountForm && deleteAccountDialog) {
		deleteAccountForm.addEventListener('submit', async (event) => {
			event.preventDefault();

			const submitButton = deleteAccountForm.querySelector('button[type="submit"]');
			submitButton.disabled = true;

			const verificationData = new FormData(deleteAccountForm);
			verificationData.delete('_method');

			try {
				const response = await fetch(deleteAccountForm.dataset.verifyUrl, {
					method: 'POST',
					body: verificationData,
					headers: {
						Accept: 'application/json',
						'X-Requested-With': 'XMLHttpRequest',
					},
					credentials: 'same-origin',
				});

				if (response.ok) {
					deleteAccountDialog.showModal();
					return;
				}

				let message = accountSettingsNotice.dataset.errorMessage;

				try {
					const data = await response.json();
					message = Object.values(data.errors ?? {}).flat()[0] ?? data.message ?? message;
				} catch {
					// Keep the generic message when the response is not JSON.
				}

				showNotice(message, true);
			} catch {
				showNotice(accountSettingsNotice.dataset.errorMessage, true);
			} finally {
				submitButton.disabled = false;
			}
		});

		deleteAccountDialog.querySelector('[data-delete-account-cancel]').addEventListener('click', () => {
			deleteAccountDialog.close();
		});

		deleteAccountDialog.querySelector('[data-delete-account-confirm]').addEventListener('click', () => {
			deleteAccountForm.submit();
		});
	}
}

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
