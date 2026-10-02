import { Controller } from '@hotwired/stimulus';

/**
 * The homepage hero slider.
 *
 * The theme ships a real slider — an `.active` class on one `.hero-slide`, previous/next buttons
 * and a "1/3" counter — and its own script is a page-level `querySelectorAll` that would also
 * bind to any other slider and cannot be reasoned about from a template. This is the same
 * behaviour scoped to the one element it decorates.
 *
 * With scripting unavailable the first slide is already marked `active` in the markup, so the
 * hero still shows a headline and its image; only the automatic advance and the controls are
 * lost, and they were never the content.
 */
export default class extends Controller {
    static targets = ['slide', 'count'];
    static values = { interval: { type: Number, default: 4000 } };

    connect() {
        this.index = this.slideTargets.findIndex((slide) => slide.classList.contains('active'));
        if (this.index < 0) {
            this.index = 0;
        }

        this.onKeydown = this.onKeydown.bind(this);
        this.element.addEventListener('keydown', this.onKeydown);

        if (this.hasMultipleSlides) {
            this.timer = window.setInterval(() => this.show(this.index + 1), this.intervalValue);
        }
    }

    disconnect() {
        this.pause();
        this.element.removeEventListener('keydown', this.onKeydown);
    }

    get hasMultipleSlides() {
        return this.slideTargets.length > 1;
    }

    next() {
        this.show(this.index + 1);
    }

    previous() {
        this.show(this.index - 1);
    }

    show(index) {
        const total = this.slideTargets.length;
        if (total < 2) {
            return;
        }

        this.pause();
        this.index = (index + total) % total;

        this.slideTargets.forEach((slide, position) => {
            const active = position === this.index;
            slide.classList.toggle('active', active);
            // A hidden slide stays in the accessibility tree otherwise, and a screen reader
            // would read three headlines where the visitor sees one.
            slide.setAttribute('aria-hidden', String(!active));
        });

        if (this.hasCountTarget) {
            this.countTarget.textContent = `${this.index + 1}/${total}`;
        }

        this.timer = window.setInterval(() => this.show(this.index + 1), this.intervalValue);
    }

    pause() {
        if (this.timer) {
            window.clearInterval(this.timer);
            this.timer = null;
        }
    }

    onKeydown(event) {
        if ('ArrowLeft' === event.key) {
            this.previous();
        } else if ('ArrowRight' === event.key) {
            this.next();
        } else {
            return;
        }

        event.preventDefault();
    }
}
