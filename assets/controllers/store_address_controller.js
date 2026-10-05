import { Controller } from '@hotwired/stimulus';

/**
 * İl seçildiğinde ilçe kutusunu o ile ait ilçelerle daraltan kontrolcü.
 *
 * Kutu, JavaScript çalışmasa da eksiksiz gönderilebilsin diye sunucuda tüm ilçelerle
 * doldurulur; bu kontrolcü yalnızca seçime göre görünmez olanları gizler, geçersiz bir
 * seçim kalmışsa değeri temizler ve il seçilmeden kutuya erişimi kapatır. Katalog sayfaya
 * JSON olarak gömülür; il değiştiğinde ilçe listesi için yeni bir istek atmaz.
 *
 * Güvenlik bu kontrolcüye değil sunucuya aittir: gizlenen bir seçenek yine de gönderilebilir
 * ve gönderilirse sunucu ilçenin o ile ait olmadığını kendisi reddeder.
 */
export default class extends Controller {
    static targets = ['city', 'district'];
    static values = { districts: Object };

    connect() {
        this.update();
    }

    update() {
        const allowed = this.districtsValue[this.cityTarget.value] || [];

        for (const option of this.districtTarget.options) {
            const visible = option.value === '' || allowed.includes(option.value);
            option.hidden = !visible;
            option.disabled = !visible;
        }

        if (this.districtTarget.selectedOptions[0]?.disabled) {
            this.districtTarget.value = '';
        }

        // İl seçilmeden bir ilçe seçilemez: kutu kapalıyken gönderilebilir bir değer kalmaz,
        // dolayısıyla tek başına bir ilçe kaydedilemez.
        this.districtTarget.disabled = allowed.length === 0;
    }
}
