

import Alpine from '@alpinejs/csp';

window.Alpine = Alpine;

Alpine.data('escalationQueue', () => ({
    timer: null,

    init() {
        this.refresh();
        this.timer = window.setInterval(() => this.refresh(), 15000);
    },

    destroy() {
        window.clearInterval(this.timer);
    },

    async refresh() {
        const status = this.$el.querySelector('[data-queue-refresh-status]');
        const url = new URL(this.$el.dataset.refreshUrl, window.location.origin);
        url.searchParams.set('_partial', '1');

        try {
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`Queue refresh failed with status ${response.status}.`);
            }

            const html = await response.text();
            const documentFragment = new DOMParser().parseFromString(html, 'text/html');
            const updatedQueue = documentFragment.querySelector('[data-escalation-queue-content]');
            const currentQueue = this.$el.querySelector('[data-escalation-queue-content]');

            if (!updatedQueue || !currentQueue) {
                throw new Error('The refreshed response did not contain an escalation queue.');
            }

            currentQueue.innerHTML = updatedQueue.innerHTML;
            status.textContent = `Updated ${new Date().toLocaleTimeString()}`;
        } catch (error) {
            status.textContent = 'Live refresh is temporarily unavailable.';
            console.error(error);
        }
    },
}));

Alpine.start();

document.addEventListener('submit', (event) => {
    if (!(event.target instanceof HTMLFormElement)) {
        return;
    }

    const confirmationMessage = event.target.dataset.confirm;

    if (confirmationMessage && ! window.confirm(confirmationMessage)) {
        event.preventDefault();
    }
});

const paymentVerificationButton = document.getElementById('verify-mpesa-payment');

if (paymentVerificationButton) {
    paymentVerificationButton.addEventListener('click', async () => {
        const result = document.getElementById('mpesa-verification-result');

        if (! result) {
            throw new Error('Payment verification result element is missing.');
        }

        paymentVerificationButton.disabled = true;
        result.textContent = 'Checking payment status…';

        try {
            let lastError;

            for (let attempt = 1; attempt <= 3; attempt++) {
                const controller = new AbortController();
                const timeout = window.setTimeout(() => controller.abort(), 8000);

                try {
                    const response = await fetch(paymentVerificationButton.dataset.statusUrl, {
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                    });
                    const payload = await response.json().catch(() => ({}));

                    if (response.ok && payload.success === true) {
                        result.textContent = payload.data?.ResultDesc
                            || payload.data?.ResponseDescription
                            || 'Payment status received.';
                        return;
                    }

                    lastError = new Error(payload.message || 'Payment status could not be verified.');

                    if (response.status < 500 || attempt === 3) {
                        break;
                    }
                } catch (error) {
                    lastError = error;

                    if (attempt === 3) {
                        break;
                    }
                } finally {
                    window.clearTimeout(timeout);
                }

                await new Promise((resolve) => window.setTimeout(resolve, attempt * 500));
            }

            throw lastError || new Error('Payment status could not be verified.');
        } catch (error) {
            result.textContent = error instanceof Error
                && error.name !== 'AbortError'
                ? error.message
                : 'Payment status check timed out. Please try again.';
        } finally {
            paymentVerificationButton.disabled = false;
        }
    });
}

let activeModalTrigger = null;
const focusableSelector = "a, button, input:not([type='hidden']), textarea, select, details, [tabindex]:not([tabindex='-1'])";

function focusableElements(modal) {
    return [...modal.querySelectorAll(focusableSelector)]
        .filter((element) => ! element.hasAttribute('disabled'));
}

function openModal(modal, trigger) {
    if (! modal) {
        return;
    }

    activeModalTrigger = trigger;
    modal.hidden = false;
    modal.dataset.modalOpen = 'true';
    document.body.classList.add('overflow-y-hidden');

    if (modal.dataset.modalFocusable === 'true') {
        window.setTimeout(() => focusableElements(modal)[0]?.focus(), 100);
    }
}

function closeModal(modal) {
    if (! modal) {
        return;
    }

    modal.hidden = true;
    modal.dataset.modalOpen = 'false';
    document.body.classList.remove('overflow-y-hidden');
    activeModalTrigger?.focus();
    activeModalTrigger = null;
}

document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) {
        return;
    }

    const openTrigger = event.target.closest('[data-open-modal]');

    if (openTrigger) {
        event.preventDefault();
        const modalName = openTrigger.dataset.openModal;
        const modal = [...document.querySelectorAll('[data-modal-name]')]
            .find((candidate) => candidate.dataset.modalName === modalName);

        openModal(modal, openTrigger);

        return;
    }

    const closeTrigger = event.target.closest('[data-close-modal]');
    const overlay = event.target.closest('[data-modal-overlay]');

    if (closeTrigger || overlay) {
        const modalName = closeTrigger?.dataset.closeModal;
        const modal = modalName
            ? [...document.querySelectorAll('[data-modal-name]')]
                .find((candidate) => candidate.dataset.modalName === modalName)
            : overlay?.closest('[data-modal-name]');

        closeModal(modal);
    }
});

document.addEventListener('keydown', (event) => {
    const modal = document.querySelector('[data-modal-open="true"]');

    if (! modal) {
        return;
    }

    if (event.key === 'Escape') {
        closeModal(modal);

        return;
    }

    if (event.key !== 'Tab' || modal.dataset.modalFocusable !== 'true') {
        return;
    }

    const focusableItems = focusableElements(modal);

    if (focusableItems.length === 0) {
        return;
    }

    const activeIndex = focusableItems.indexOf(document.activeElement);

    if (event.shiftKey && activeIndex <= 0) {
        event.preventDefault();
        focusableItems[focusableItems.length - 1].focus();
    } else if (! event.shiftKey && (activeIndex < 0 || activeIndex === focusableItems.length - 1)) {
        event.preventDefault();
        focusableItems[0].focus();
    }
});

document.querySelectorAll('[data-modal-open="true"]').forEach((modal) => {
    document.body.classList.add('overflow-y-hidden');

    if (modal.dataset.modalFocusable === 'true') {
        window.setTimeout(() => focusableElements(modal)[0]?.focus(), 100);
    }
});

const siteMenuToggle = document.querySelector('[data-site-menu-toggle]');
const siteMobileMenu = document.getElementById('site-mobile-menu');

if (siteMenuToggle && siteMobileMenu) {
    const openIcon = siteMenuToggle.querySelector('[data-menu-open-icon]');
    const closeIcon = siteMenuToggle.querySelector('[data-menu-close-icon]');

    siteMenuToggle.addEventListener('click', () => {
        const isOpen = siteMenuToggle.getAttribute('aria-expanded') !== 'true';

        siteMenuToggle.setAttribute('aria-expanded', String(isOpen));
        siteMobileMenu.hidden = !isOpen;

        openIcon?.classList.toggle('hidden', isOpen);
        closeIcon?.classList.toggle('hidden', !isOpen);
    });
}
