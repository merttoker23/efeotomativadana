import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        interval: { type: Number, default: 0 },
    };

    connect() {
        this.refreshing = false;
        this.timer = null;
        if (this.intervalValue > 0) {
            this.timer = window.setInterval(() => this.refresh(), this.intervalValue * 1000);
        }
    }

    disconnect() {
        if (null !== this.timer) {
            window.clearInterval(this.timer);
        }
    }

    async refresh() {
        if (this.refreshing || document.hidden) {
            return;
        }
        this.refreshing = true;
        try {
            const response = await fetch(window.location.href, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) {
                return;
            }
            const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
            const replacement = parsed.querySelector('[data-controller~="b2b-dashboard"]');
            if (replacement) {
                this.element.replaceWith(replacement);
            }
        } catch {
            // A transient refresh failure leaves the current page usable.
        } finally {
            this.refreshing = false;
        }
    }
}
