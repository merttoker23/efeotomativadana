<?php

declare(strict_types=1);

namespace App\Tests\Unit\Settings;

use App\Module\Settings\TurkishGeography;
use PHPUnit\Framework\TestCase;

/**
 * İl seçimi serbest metin olsaydı, "Adaa" da bir il olurdu. Katalog bunu bir veri sorunu
 * değil, bir karşılaştırma sorunu yapar: ilin adı katalogda mı, ilçenin adı o ilin
 * ilçeleri arasında mı.
 */
final class TurkishGeographyTest extends TestCase
{
    public function testTheCatalogIsComplete(): void
    {
        self::assertCount(81, TurkishGeography::provinces());
        self::assertSame(973, array_sum(array_map('count', TurkishGeography::districtsByProvince())));
        self::assertSame(
            ['Adana', 'Adıyaman', 'Afyonkarahisar', 'Ağrı', 'Aksaray', 'Amasya', 'Ankara'],
            array_slice(TurkishGeography::provinces(), 0, 7),
        );
    }

    public function testTheProvinceOrderIsTurkishRatherThanBytewise(): void
    {
        // A bytewise sort would put "Agri"-shaped names before "Afyon" and "Ankara" after
        // "Antalya" only by accident; this asserts the list a person would read aloud.
        $provinces = TurkishGeography::provinces();

        self::assertLessThan(array_search('Afyonkarahisar', $provinces, true), array_search('Adıyaman', $provinces, true));
        self::assertLessThan(array_search('Artvin', $provinces, true), array_search('Ardahan', $provinces, true));
        self::assertSame('İstanbul', $provinces[array_search('İzmir', $provinces, true) - 1]);
    }

    public function testADistrictIsAcceptedOnlyUnderItsOwnProvince(): void
    {
        self::assertTrue(TurkishGeography::hasDistrict('Adana', 'Seyhan'));
        self::assertTrue(TurkishGeography::hasDistrict('Gaziantep', 'Şehitkamil'));
        // The same district name under the wrong province, and a province that does not exist.
        self::assertFalse(TurkishGeography::hasDistrict('Adana', 'Şehitkamil'));
        self::assertFalse(TurkishGeography::hasDistrict('Adaa', 'Seyhan'));
        self::assertFalse(TurkishGeography::hasDistrict(null, 'Seyhan'));
        self::assertFalse(TurkishGeography::hasDistrict('Adana', ''));
        self::assertFalse(TurkishGeography::hasDistrict('Adana', 'Adaa'));
    }

    public function testNamesAreMatchedTheWayATurkishFormTypesThem(): void
    {
        self::assertTrue(TurkishGeography::hasProvince('adana'));
        self::assertTrue(TurkishGeography::hasProvince('  ADANA '));
        self::assertTrue(TurkishGeography::hasProvince('Istanbul'));
        self::assertTrue(TurkishGeography::hasProvince('İSTANBUL'));
        self::assertTrue(TurkishGeography::hasProvince('şırnak'));
        self::assertFalse(TurkishGeography::hasProvince('Ispartaa'));
        self::assertFalse(TurkishGeography::hasProvince(null));
        self::assertTrue(TurkishGeography::hasDistrict('Adana', 'seyhan'));
    }

    public function testAnUnknownNameIsResolvedToNothingRatherThanToSomethingElse(): void
    {
        self::assertNull(TurkishGeography::normalizeProvince('Bogus'));
        self::assertNull(TurkishGeography::normalizeDistrict('Adana', 'Şişli'));
        self::assertSame([], TurkishGeography::districtsOf('Bogus'));
        self::assertSame('Adana', TurkishGeography::normalizeProvince('adana'));
        self::assertSame('Çukurova', TurkishGeography::normalizeDistrict('Adana', 'çukurova'));
    }

    public function testTheDistrictOptionsAreDeduplicatedAndAlphabetical(): void
    {
        $all = TurkishGeography::allDistricts();

        self::assertSame($all, array_values(array_unique($all)));
        self::assertContains('Seyhan', $all);
        self::assertContains('Şehitkamil', $all);
        $positions = array_flip($all);
        // "Ş" reads as "s" in Turkish, so Şehitkamil sorts among the S-names rather than last.
        self::assertLessThan($positions['Şehitkamil'], $positions['Çukurova']);
        self::assertLessThan($positions['Seyhan'], $positions['Şehitkamil']);
    }
}
