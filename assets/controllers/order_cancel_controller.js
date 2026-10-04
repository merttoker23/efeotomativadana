import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    confirm(event) {
        if (!window.confirm('Siparişinizi iptal etmek istediğinize emin misiniz? Tahsil edilmiş ödeme varsa tamamı iade edilecektir.')) {
            event.preventDefault();
        }
    }
}
