import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['minInput', 'maxInput', 'minRange', 'maxRange', 'track'];

    connect() {
        this.inputsChanged();
    }

    inputsChanged() {
        if (this.hasMinRangeTarget) {
            this.minRangeTarget.value = this.minInputTarget.value || this.minRangeTarget.min;
            this.maxRangeTarget.value = this.maxInputTarget.value || this.maxRangeTarget.max;
        }
        this.render();
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
        // The fields are typed into, so they take the plain decimal the listing reads back, not
        // the fixed two-decimal form the slider steps in.
        this.minInputTarget.value = this.decimal(min);
        this.maxInputTarget.value = this.decimal(max);
        this.render();
    }

    decimal(value) {
        return Number(value).toFixed(2).replace(/\.?0+$/, '') || '0';
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
