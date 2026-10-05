import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.restore();
        if (window.location.hash === '#catalog-results') {
            document.getElementById('catalog-results')?.focus({ preventScroll: true });
        }
    }

    disconnect() {
        clearTimeout(this.submitTimer);
    }

    restore() {
        this.submitting = false;
        clearTimeout(this.submitTimer);
    }

    debounce() {
        clearTimeout(this.submitTimer);
        this.submitTimer = setTimeout(() => this.submit(), 600);
    }

    submit(event) {
        event?.preventDefault();
        clearTimeout(this.submitTimer);
        if (this.submitting || !this.element.reportValidity()) return;

        const destination = new URL(this.element.action, window.location.href);
        destination.search = window.location.search;
        this.copyFields(destination, new FormData(this.element));
        destination.searchParams.delete('page');
        destination.hash = 'catalog-results';
        if (destination.href === window.location.href) return;
        const current = new URL(window.location.href);
        // A fragment-only navigation keeps this controller alive and does not emit pageshow.
        this.submitting = destination.pathname !== current.pathname || destination.search !== current.search;
        window.location.assign(destination.href);
    }

    clear(event) {
        event?.preventDefault();
        clearTimeout(this.submitTimer);
        // Empty the editable filters, then take the bare address of the current listing.
        for (const field of this.element.elements) {
            if (field.name === 'min_price' || field.name === 'max_price') {
                field.value = '';
            } else if (field.name === 'availability') {
                field.checked = false;
            }
        }
        const destination = new URL(this.element.action, window.location.href);
        for (const key of ['q', 'sort', 'category', 'brand', 'min_price', 'max_price', 'availability', 'page']) {
            destination.searchParams.delete(key);
        }
        destination.hash = 'catalog-results';
        if (destination.href === window.location.href) return;
        this.submitting = true;
        window.location.assign(destination.href);
    }

    navigate(event) {
        // Preserve native new-tab/window actions and the working links without JavaScript.
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        event.preventDefault();
        clearTimeout(this.submitTimer);
        if (!this.element.reportValidity()) return;

        const destination = new URL(event.currentTarget.href, window.location.href);
        const knownFilters = ['q', 'sort', 'category', 'brand', 'min_price', 'max_price', 'availability', 'page'];
        for (const [key, value] of new URL(window.location.href).searchParams) {
            if (!knownFilters.includes(key) && !destination.searchParams.has(key)) destination.searchParams.append(key, value);
        }
        const fields = new FormData(this.element);
        // Category/brand routing comes from the clicked link; editable fields come from the form.
        this.copyFields(destination, fields, ['q', 'sort', 'min_price', 'max_price', 'availability']);
        window.location.assign(destination.href);
    }

    copyFields(destination, fields, keys = ['q', 'sort', 'category', 'brand', 'min_price', 'max_price', 'availability']) {
        for (const key of keys) {
            const value = fields.get(key);
            if (typeof value === 'string' && value.trim() !== '') destination.searchParams.set(key, value);
            else destination.searchParams.delete(key);
        }
    }
}
