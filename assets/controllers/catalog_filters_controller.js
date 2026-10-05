import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    navigate(event) {
        // Preserve native new-tab/window actions and the working links without JavaScript.
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        event.preventDefault();
        if (!this.element.reportValidity()) return;

        const destination = new URL(event.currentTarget.href, window.location.href);
        const fields = new FormData(this.element);
        // Category/brand routing comes from the clicked link; editable fields come from the form.
        for (const key of ['q', 'sort', 'min_price', 'max_price', 'availability']) {
            const value = fields.get(key);
            if (typeof value === 'string' && value.trim() !== '') destination.searchParams.set(key, value);
            else destination.searchParams.delete(key);
        }
        window.location.assign(destination.href);
    }
}
