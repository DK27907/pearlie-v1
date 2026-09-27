

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
