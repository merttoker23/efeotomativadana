import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['main', 'thumbnail'];

    select(event) {
        const button = event.currentTarget;
        const image = button.querySelector('img');
        this.mainTarget.src = image.src;
        this.mainTarget.alt = image.alt;
        for (const dimension of ['width', 'height']) {
            const value = image.getAttribute(dimension);
            if (value) {
                this.mainTarget.setAttribute(dimension, value);
            } else {
                this.mainTarget.removeAttribute(dimension);
            }
        }
        this.thumbnailTargets.forEach((thumbnail) => {
            thumbnail.setAttribute('aria-pressed', String(thumbnail === button));
        });
    }
}
