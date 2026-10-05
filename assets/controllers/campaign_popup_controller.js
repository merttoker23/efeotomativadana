import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['close', 'preference', 'title'];
    static values = { key: String, delay: Number, allowDismiss: Boolean };

    connect() {
        this.timer = window.setTimeout(() => this.open(), Math.max(0, this.delayValue) * 1000);
    }

    get storageKey() { return `efe-campaign:${this.keyValue}`; }

    read(storage) {
        try { return window[storage].getItem(this.storageKey) === '1'; } catch { return false; }
    }

    remember(storage) {
        try { window[storage].setItem(this.storageKey, '1'); } catch { /* Storage can be unavailable in private browsing. */ }
    }

    open() {
        if (this.dismissed || this.element.open || this.read('sessionStorage') || this.read('localStorage') || typeof this.element.showModal !== 'function') return;
        if (document.querySelector('dialog[open]')) return;
        this.opener = document.activeElement;
        this.element.showModal();
        this.overflow = [document.body.style.overflow, document.documentElement.style.overflow];
        document.body.style.overflow = 'hidden';
        document.documentElement.style.overflow = 'hidden';
        this.titleTarget.focus({ preventScroll: true });
    }

    close() {
        if (this.element.open) this.element.close();
    }

    cancel(event) {
        event.preventDefault();
        this.close();
    }

    closed() {
        this.dismissed = true;
        this.remember('sessionStorage');
        if (this.allowDismissValue && this.hasPreferenceTarget && this.preferenceTarget.checked) this.remember('localStorage');
        this.restore();
    }

    restore() {
        if (!this.overflow) return;
        [document.body.style.overflow, document.documentElement.style.overflow] = this.overflow;
        this.overflow = null;
        if (this.opener?.isConnected) this.opener.focus({ preventScroll: true });
    }

    outside(event) {
        if (event.target !== this.element) return false;
        const bounds = this.element.getBoundingClientRect();
        return event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom;
    }

    backdropStart(event) { this.startedOutside = this.outside(event); }

    backdrop(event) {
        if (this.startedOutside && this.outside(event)) this.close();
        this.startedOutside = false;
    }

    disconnect() {
        window.clearTimeout(this.timer);
        this.close();
        this.restore();
    }
}
