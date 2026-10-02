import { Controller } from '@hotwired/stimulus';

/**
 * The homepage product tabs.
 *
 * The reference theme only moves an `.active` class between the tab buttons; the product grid
 * behind them never changes. That is a picture of a tab strip rather than a tab strip, so this
 * controller gives each tab its own `tabpanel` and shows exactly one of them, moves the roving
 * tabindex with the arrow keys, and turns the whole control off when there is nothing to switch
 * between.
 *
 * With scripting unavailable the first panel is the only one rendered unhidden, which is the same
 * state the controller establishes on connect.
 */
export default class extends Controller {
    static targets = ['tab', 'panel'];

    connect() {
        this.active = this.tabTargets.findIndex((tab) => 'true' === tab.getAttribute('aria-selected'));
        if (this.active < 0) {
            this.active = 0;
        }

        this.activate(this.active);

        this.onKeydown = this.onKeydown.bind(this);
        this.element.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        this.element.removeEventListener('keydown', this.onKeydown);
    }

    select(event) {
        const index = this.tabTargets.indexOf(event.currentTarget);
        if (index < 0) {
            return;
        }

        this.activate(index);
        this.tabTargets[index].focus();
    }

    activate(index) {
        this.active = index;

        this.tabTargets.forEach((tab, position) => {
            const selected = position === index;
            tab.classList.toggle('active', selected);
            tab.setAttribute('aria-selected', String(selected));
            // Roving tabindex: one stop for the whole strip, arrows move within it.
            tab.setAttribute('tabindex', selected ? '0' : '-1');
        });

        this.panelTargets.forEach((panel, position) => {
            panel.hidden = position !== index;
        });
    }

    onKeydown(event) {
        const total = this.tabTargets.length;
        if (total < 2) {
            return;
        }

        let target = null;
        if ('ArrowRight' === event.key) {
            target = (this.active + 1) % total;
        } else if ('ArrowLeft' === event.key) {
            target = (this.active - 1 + total) % total;
        } else if ('Home' === event.key) {
            target = 0;
        } else if ('End' === event.key) {
            target = total - 1;
        } else {
            return;
        }

        event.preventDefault();
        this.activate(target);
        this.tabTargets[target].focus();
    }
}
