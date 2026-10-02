import { Controller } from '@hotwired/stimulus';

/**
 * The image control on a section form.
 *
 * An administrator choosing a hero or a banner is choosing a picture, so the picture is shown
 * before the section is saved: a file that has just been picked is read in the browser and shown
 * straight away, and a library entry is shown as soon as it is selected. Nothing is uploaded by
 * this controller — the form still posts the file, and the server still decides what it accepts —
 * so what is previewed is a promise the server is free to decline, not a stored image.
 */
export default class extends Controller {
    static targets = ['file', 'select', 'preview'];

    preview() {
        const file = this.fileTarget.files?.[0];

        if (!file) {
            this.showChosen();
            return;
        }

        if (!file.type.startsWith('image/')) {
            return;
        }

        const reader = new FileReader();
        reader.addEventListener('load', () => {
            this.previewTarget.src = reader.result;
            this.previewTarget.removeAttribute('width');
            this.previewTarget.removeAttribute('height');
            this.previewTarget.hidden = false;
        });
        reader.readAsDataURL(file);
    }

    showChosen() {
        const path = this.selectTarget.value;

        if (!path) {
            this.previewTarget.hidden = true;

            return;
        }

        this.previewTarget.src = path;
        this.previewTarget.hidden = false;
    }
}
