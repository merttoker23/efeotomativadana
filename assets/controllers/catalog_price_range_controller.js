import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['minInput', 'maxInput', 'minCanonical', 'maxCanonical', 'minRange', 'maxRange', 'track'];

    connect() {
        this.inputsChanged();
    }

    inputsChanged() {
        for (const bound of ['min', 'max']) {
            const input = this[`${bound}InputTarget`];
            const canonical = this.parse(input.value);
            this[`${bound}CanonicalTarget`].value = canonical ?? '';
            input.setCustomValidity(canonical === null ? 'Geçerli, negatif olmayan bir fiyat girin (ör. 1.000,00).' : '');
        }
        const min = this.minCanonicalTarget.value;
        const max = this.maxCanonicalTarget.value;
        if (min !== '' && max !== '' && BigInt(min.replace('.', '')) > BigInt(max.replace('.', ''))) {
            this.maxInputTarget.setCustomValidity('Maksimum fiyat minimum fiyattan küçük olamaz.');
        }
        if (this.hasMinRangeTarget) {
            this.minRangeTarget.value = min || this.minRangeTarget.min;
            this.maxRangeTarget.value = max || this.maxRangeTarget.max;
        }
        this.render();
    }

    parse(value) {
        value = value.trim();
        if (value === '') return '';
        if (!/^(?:\d+|\d{1,3}(?:\.\d{3})+)(?:,\d{1,2})?$/.test(value)) return null;
        const [major, fraction = ''] = value.replaceAll('.', '').split(',');
        const canonical = `${major.replace(/^0+(?=\d)/, '')}.${fraction.padEnd(2, '0')}`;
        return BigInt(major + fraction.padEnd(2, '0')) <= 9223372036854775807n ? canonical : null;
    }

    format(value) {
        const [major, fraction = ''] = (typeof value === 'number' ? value.toFixed(2) : value).split('.');
        return `${major.replace(/\B(?=(\d{3})+(?!\d))/g, '.')},${fraction.padEnd(2, '0')}`;
    }

    formatInputs() {
        this.inputsChanged();
        for (const bound of ['min', 'max']) {
            const canonical = this[`${bound}CanonicalTarget`].value;
            if (canonical !== '') this[`${bound}InputTarget`].value = this.format(canonical);
        }
    }

    rangeChanged(event) {
        let min = Number(this.minRangeTarget.value);
        let max = Number(this.maxRangeTarget.value);
        if (min > max) {
            if (event.target === this.minRangeTarget) max = min;
            else min = max;
        }
        this.minRangeTarget.value = min;
        this.maxRangeTarget.value = max;
        this.minInputTarget.value = this.format(min);
        this.maxInputTarget.value = this.format(max);
        this.inputsChanged();
    }

    render() {
        if (!this.hasTrackTarget) return;
        const lower = Number(this.minRangeTarget.min);
        const span = Number(this.maxRangeTarget.max) - lower;
        const percentage = (value) => span > 0 ? 100 * (Number(value) - lower) / span : 0;
        this.trackTarget.style.setProperty('--price-from', `${percentage(this.minRangeTarget.value)}%`);
        this.trackTarget.style.setProperty('--price-to', `${span > 0 ? percentage(this.maxRangeTarget.value) : 100}%`);
        // Keep both handles reachable when they meet at either end of the track.
        this.minRangeTarget.style.zIndex = Number(this.minRangeTarget.value) === Number(this.maxRangeTarget.max) ? '4' : '2';
        this.maxRangeTarget.style.zIndex = '3';
    }
}
