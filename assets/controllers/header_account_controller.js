import { Controller } from '@hotwired/stimulus';

/**
 * The header's account dropdown.
 *
 * The reference theme's account pill carries a caret, which says "there is a list under here", but
 * the reference never opens one. This controller does: the pill is a real `<button>` with
 * `aria-expanded` and `aria-controls`, and the list it owns is a labelled menu that closes again on
 * Escape, on a click anywhere outside it, and when focus leaves it for anywhere else.
 *
 * Keyboard behaviour is the disclosure a menu of links is expected to have: Down or Up on the
 * trigger opens the list and lands on the first or the last item, Escape closes it and returns
 * focus to the trigger, and Tab moves through the items in order and then out of the control, which
 * closes it behind the keyboard rather than leaving an open list nobody can dismiss.
 *
 * It is a desktop control. The reference drops its action pills below 1024px — the header keeps its
 * logo, its search and the menu button there — so this one is not on the page at those widths and
 * the mobile navigation's own "Hesabım" row is what a small screen uses instead. The media query
 * here mirrors that breakpoint rather than trusting the stylesheet to hide a control that is still
 * scriptable.
 */
export default class extends Controller {
    static targets = ['trigger', 'menu'];

    connect() {
        this.onDocumentClick = this.onDocumentClick.bind(this);
        this.onKeydown = this.onKeydown.bind(this);
        this.onFocusOut = this.onFocusOut.bind(this);
        this.onBreakpointChange = this.onBreakpointChange.bind(this);
        this.desktop = window.matchMedia('(min-width: 1025px)');

        document.addEventListener('click', this.onDocumentClick);
        this.element.addEventListener('keydown', this.onKeydown);
        this.element.addEventListener('focusout', this.onFocusOut);
        this.desktop.addEventListener('change', this.onBreakpointChange);
    }

    disconnect() {
        document.removeEventListener('click', this.onDocumentClick);
        this.element.removeEventListener('keydown', this.onKeydown);
        this.element.removeEventListener('focusout', this.onFocusOut);
        this.desktop.removeEventListener('change', this.onBreakpointChange);
    }

    toggle(event) {
        event.preventDefault();

        if (this.isOpen()) {
            this.close();

            return;
        }

        this.open();
    }

    isOpen() {
        return !this.menuTarget.hidden;
    }

    open() {
        if (!this.desktop.matches || this.isOpen()) {
            return;
        }

        this.menuTarget.hidden = false;
        this.element.classList.add('is-open');
        this.triggerTarget.setAttribute('aria-expanded', 'true');
    }

    close(returnFocus = false) {
        if (!this.isOpen()) {
            return;
        }

        this.menuTarget.hidden = true;
        this.element.classList.remove('is-open');
        this.triggerTarget.setAttribute('aria-expanded', 'false');

        if (returnFocus) {
            this.triggerTarget.focus();
        }
    }

    onDocumentClick(event) {
        if (this.element.contains(event.target)) {
            return;
        }

        this.close();
    }

    onFocusOut(event) {
        if (event.relatedTarget && this.element.contains(event.relatedTarget)) {
            return;
        }

        this.close();
    }

    onKeydown(event) {
        if ('Escape' === event.key) {
            if (this.isOpen()) {
                event.preventDefault();
                this.close(true);
            }

            return;
        }

        if (!['ArrowDown', 'ArrowUp'].includes(event.key) || event.target !== this.triggerTarget) {
            return;
        }

        event.preventDefault();
        this.open();

        const items = this.items();
        if (0 === items.length) {
            return;
        }

        this.focus('ArrowUp' === event.key ? items.length - 1 : 0);
    }

    items() {
        return [...this.menuTarget.querySelectorAll('a[href], button:not([disabled])')];
    }

    focus(index) {
        const item = this.items()[index];

        if (item instanceof HTMLElement) {
            item.focus();
        }
    }

    onBreakpointChange() {
        // Crossing the breakpoint either hides the pill or brings it back; either way a list left
        // open by the other one would be a list with nothing to show it.
        this.close();
    }
}