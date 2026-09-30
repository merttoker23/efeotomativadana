import { Controller } from '@hotwired/stimulus';

/**
 * Asks before a destructive form is submitted.
 *
 * This replaces an `onsubmit="return confirm(...)"` attribute, which had two problems: an
 * inline handler is refused outright by a Content-Security-Policy without `unsafe-inline`, so
 * the confirmation silently stopped running in exactly the deployments that lock the policy
 * down, and a blocking browser dialog is not something a keyboard or assistive-technology user
 * can drive the way the rest of this store's controls are driven.
 */
export default class extends Controller {
    static targets = ['message'];

    static values = { message: { type: String, default: 'Are you sure?' } };

    submit(event) {
        if (!window.confirm(this.messageValue)) {
            event.preventDefault();
        }
    }
}