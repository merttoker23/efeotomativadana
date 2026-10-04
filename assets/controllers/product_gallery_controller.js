import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['main', 'thumbnail', 'dialog', 'zoom', 'zoomThumbnails', 'position', 'previous', 'next'];

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

    open(event) {
        if (!this.hasDialogTarget || this.dialogTarget.open || typeof this.dialogTarget.showModal !== 'function') {
            return;
        }

        this.opener = event.currentTarget;
        this.images = this.thumbnailTargets.length
            ? this.thumbnailTargets.map((button) => button.querySelector('img'))
            : [this.mainTarget];
        this.index = Math.max(0, this.thumbnailTargets.findIndex((button) => button.getAttribute('aria-pressed') === 'true'));

        // Create modal thumbnails only on demand; a closed modal never fetches images.
        this.zoomThumbnailsTarget.replaceChildren();
        if (this.images.length > 1) {
            this.images.forEach((image, index) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'product-gallery-thumbnail';
                button.setAttribute('aria-label', `${index + 1}. görseli büyüt`);
                button.setAttribute('data-action', 'product-gallery#selectZoom');
                button.dataset.index = String(index);
                const preview = document.createElement('img');
                preview.src = image.src;
                preview.alt = '';
                preview.loading = 'lazy';
                preview.decoding = 'async';
                preview.width = 64;
                preview.height = 64;
                button.append(preview);
                this.zoomThumbnailsTarget.append(button);
            });
        }

        this.previousTarget.hidden = this.images.length < 2;
        this.nextTarget.hidden = this.images.length < 2;
        this.renderZoom();
        this.dialogTarget.showModal();
        this.savedOverflow = {
            root: document.documentElement.style.overflow,
            body: document.body.style.overflow,
        };
        document.documentElement.style.overflow = 'hidden';
        document.body.style.overflow = 'hidden';
    }

    selectZoom(event) {
        this.index = Number(event.currentTarget.dataset.index);
        this.renderZoom();
    }

    previous() {
        this.index = (this.index + this.images.length - 1) % this.images.length;
        this.renderZoom();
    }

    next() {
        this.index = (this.index + 1) % this.images.length;
        this.renderZoom();
    }

    keydown(event) {
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault();
            event.key === 'ArrowLeft' ? this.previous() : this.next();
        }
    }

    renderZoom() {
        const image = this.images[this.index];
        this.zoomTarget.src = image.src;
        this.zoomTarget.alt = image.alt;
        this.positionTarget.textContent = `${this.index + 1} / ${this.images.length}`;
        Array.from(this.zoomThumbnailsTarget.children).forEach((button, index) => {
            button.setAttribute('aria-pressed', String(index === this.index));
        });
    }

    backdrop(event) {
        if (event.target !== this.dialogTarget) {
            return;
        }
        const bounds = this.dialogTarget.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) {
            this.close();
        }
    }

    close(event) {
        event?.preventDefault();
        if (this.hasDialogTarget && this.dialogTarget.open) {
            this.dialogTarget.close();
        }
        this.closed();
    }

    closed() {
        if (!this.savedOverflow) {
            return;
        }
        document.documentElement.style.overflow = this.savedOverflow.root;
        document.body.style.overflow = this.savedOverflow.body;
        this.savedOverflow = null;
        this.opener?.focus({ preventScroll: true });
    }

    disconnect() {
        this.close();
    }
}
