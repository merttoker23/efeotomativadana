import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['announcement', 'brand', 'menu', 'toggle'];

    connect() {
        this.boundCloseOnEscape = this.closeOnEscape.bind(this);
        this.boundResetForDesktop = this.resetForDesktop.bind(this);
        this.desktopMedia = window.matchMedia('(min-width: 1025px)');

        document.addEventListener('keydown', this.boundCloseOnEscape);
        this.desktopMedia.addEventListener('change', this.boundResetForDesktop);
        this.resetForDesktop(this.desktopMedia);
    }

    disconnect() {
        document.removeEventListener('keydown', this.boundCloseOnEscape);
        this.desktopMedia.removeEventListener('change', this.boundResetForDesktop);
    }

    toggleMenu() {
        const willOpen = this.menuTarget.hidden;

        this.menuTarget.hidden = !willOpen;
        this.toggleTarget.setAttribute('aria-expanded', String(willOpen));
        this.toggleTarget.setAttribute('aria-label', willOpen ? 'Menüyü kapat' : 'Menüyü aç');

        if (willOpen) {
            this.menuTarget.querySelector('a')?.focus();
        }
    }

    dismissAnnouncement() {
        this.announcementTarget.hidden = true;
    }

    closeOnEscape(event) {
        if (event.key !== 'Escape' || this.menuTarget.hidden) {
            return;
        }

        this.closeMenu();
        this.toggleTarget.focus();
    }

    resetForDesktop(mediaQuery) {
        if (mediaQuery.matches) {
            const focusWasInMenu = this.menuTarget.contains(document.activeElement);

            this.closeMenu();

            if (focusWasInMenu) {
                this.brandTarget.focus();
            }
        }
    }

    closeMenu() {
        this.menuTarget.hidden = true;
        this.toggleTarget.setAttribute('aria-expanded', 'false');
        this.toggleTarget.setAttribute('aria-label', 'Menüyü aç');
    }
}
