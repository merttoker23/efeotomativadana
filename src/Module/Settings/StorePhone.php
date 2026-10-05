<?php

declare(strict_types=1);

namespace App\Module\Settings;

/**
 * Bir mağaza telefonu, insanın yazdığı biçimden tek bir kanonik biçime indirgenir.
 *
 * Yöneticiler numarayı `+90 322 123 45 67`, `0322 123 45 67`, `322 123 45 67` ya da
 * `3221234567` olarak yazabilir; hepsi aynı numaradır. Hepsi de saklanmadan önce aynı
 * biçime gelir, çünkü footer'da bu metin okunacak ve `tel:` bağlantısı bu metinden üretilecek.
 * Doğrulanamayan bir giriş reddedilir: ham ya da bozuk veriyi saklamak, onu göstermekten
 * daha kötüdür — geçersiz bir numara footer'da yanlış bir iletişim adresi olarak görünür.
 *
 * Ülke kodu `90` ve ulusal numara 10 hane kabul edilir; sabit hat ve cep telefonu bu on hanede
 * ayrım gerektirmez, yalnızca ilk üç hane farklıdır.
 */
final class StorePhone
{
    /** Kanonik biçimin insanın okuyacağı örneği; hata mesajında kullanılır. */
    private const string DISPLAY_PATTERN = '+90 ### ### ## ##';

    /** Bir alanın kabul ettiği en uzun ham giriş; daha uzun bir şey zaten geçersizdir. */
    public const int MAX_INPUT_LENGTH = 40;

    private const int NATIONAL_LENGTH = 10;

    /**
     * Girdiyi kanonik biçime çevirir; boş giriş null, geçersiz giriş istisna fırlatır.
     *
     * Geçersiz girdi sessizce düzeltilmez. Ya doğru saklanır ya da form bir hata mesajıyla
     * döner; ikisinin arasında, "kaydedildi" görünüp sonradan yanlış çıkan bir numara yoktur.
     *
     * @throws \InvalidArgumentException
     */
    public static function normalize(?string $value): ?string
    {
        $trimmed = trim($value ?? '');
        if ('' === $trimmed) {
            return null;
        }

        // Serbest metin olmasın: yalnızca rakam ve yaygın ayraçlar kabul edilir, içlerinde en az
        // bir rakam bulunur. "abc" ve "+90 322 ABCD" böylece reddedilir; bırakılırsa footer'a ham
        // veri girer ve `tel:` bağlantısı çalışmayan bir adrese gider.
        if (1 !== preg_match('/\A[0-9+()\s.\/-]+\z/u', $trimmed) || 1 !== preg_match('/[0-9]/', $trimmed)) {
            throw new \InvalidArgumentException('Telefon numarası yalnızca rakam ve ayraç içerebilir.');
        }

        // `00`, `0090`, `90`, `0` ve hiçbir ülke kodu yazılmadan numara aynı sonuca gider.
        // `00` her uzunlukta olabilir (`0090 322 ...` on dört hane), bu yüzden uzunluğa değil
        // öneke bakılır; ülke kodu `90` ise yalnızca tam on iki haneye düşünce çıkarılır, aksi
        // hâlde `9032212345` gibi bir numaranın başındaki `90` alan kodu sanılırdı.
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (12 === strlen($digits) && str_starts_with($digits, '90')) {
            $digits = substr($digits, 2);
        }
        if (11 === strlen($digits) && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if (self::NATIONAL_LENGTH !== strlen($digits) || 1 === preg_match('/\A0/', $digits)) {
            throw new \InvalidArgumentException(sprintf('Telefon numarası %s biçiminde 10 haneli olmalı.', self::DISPLAY_PATTERN));
        }

        return sprintf(
            '+90 %s %s %s %s',
            substr($digits, 0, 3),
            substr($digits, 3, 3),
            substr($digits, 6, 2),
            substr($digits, 8, 2),
        );
    }

    /** Girdi geçerli mi? Form seviyesinde kullanılır; istisna fırlatmaz. */
    public static function isValid(?string $value): bool
    {
        try {
            return null !== self::normalize($value);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * `tel:` bağlantısının gövdesi: `+` işareti ve ayraç olmadan, örneğin `tel:+903221234567`.
     *
     * Gövde uluslararası biçimde tutulur. `tel:03221234567` bir ulusal numara olarak yorumlanır ve
     * çağıran tarafın kendi ülkesinin alan koduyla birleştirilir, yani başka bir numaraya gider.
     * Kanonik olmayan bir giriş burada yeniden kanonikleştirilir; çünkü bu yöntemin tek işi
     * "doğru numaraya giden bir bağlantı üretmek"tir ve üretemiyorsa bağlantı üretmez.
     */
    public static function link(?string $value): ?string
    {
        try {
            $canonical = self::normalize($value);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return null === $canonical ? null : 'tel:+'.(preg_replace('/\D+/', '', $canonical) ?? '');
    }
}