import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

export default class extends Controller {
    static targets = ['sidebar', 'backdrop', 'toggle'];

    connect() {
        this.desktop = window.matchMedia('(min-width: 1100px)');
        this.onResize = () => { if (this.desktop.matches) this.close(); };
        this.desktop.addEventListener('change', this.onResize);
        this.onResize();
    }

    disconnect() {
        this.desktop.removeEventListener('change', this.onResize);
        this.element.classList.remove('menu-open');
    }

    toggle() {
        if (this.element.classList.contains('menu-open')) return this.close();
        this.element.classList.add('menu-open');
        this.backdropTarget.hidden = false;
        this.toggleTarget.setAttribute('aria-expanded', 'true');
        this.sidebarTarget.querySelector('button').focus();
        this.element.querySelector('.admin-workspace').inert = true;
    }

    close() {
        const wasOpen = this.element.classList.contains('menu-open');
        this.element.classList.remove('menu-open');
        this.backdropTarget.hidden = true;
        this.toggleTarget.setAttribute('aria-expanded', 'false');
        this.element.querySelector('.admin-workspace').inert = false;
        if (wasOpen && !this.desktop.matches) this.toggleTarget.focus();
    }
}
