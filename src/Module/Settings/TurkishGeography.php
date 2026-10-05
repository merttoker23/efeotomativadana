<?php

declare(strict_types=1);

namespace App\Module\Settings;

/**
 * Türkiye'nin il ve ilçe kataloğu, tek bir yerde ve çevrimdışı.
 *
 * İl seçimi serbest metin olsaydı, kaydedilen bir "Adaa" ya da "Adan" ilçeyi de kaydedilmiş
 * olurdu: sunucu, tarayıcının gönderdiği metni doğrulayamazdı. Katalog yerel olduğu için bu
 * doğrulama bir dış API'ye gitmeden, isteğe bağlı olmayan ve her istekte aynı sonucu üreten bir
 * karşılaştırmaya dönüşür.
 *
 * Veri OCHA COD-AB (Humanitarian Data Exchange) ve isubas/turkish_cities kaynaklıdır; 81 il ve
 * 973 ilçe. Sıralama, "Ç" harfini "C" gibi okuyan Türkçe bir karşılaştırma anahtarıyla yapılır,
 * böylece liste `Ağrı, Amasya, Ankara, Antalya, Ardahan, Artvin, Aydın` biçiminde çıkar.
 *
 * Katalog adı, aranan ad; alan `İl` olduğu için büyük/küçük harf ve Türkçe harf eşlemesi
 * ayrıca ele alınır: bir form `il` alanına "adana" yazdığında da aynı kayıt bulunur.
 */
final class TurkishGeography
{
    /** @var array<string, list<string>>|null İl adı => ilçeler, ikisi de alfabetik. */
    private static ?array $districtsByProvince = null;

    /**
     * @return array<string, list<string>>
     */
    public static function districtsByProvince(): array
    {
        return self::$districtsByProvince ??= self::catalog();
    }

/**
     * @return list<string> Türkçe alfabetik sırada il adları.
     */
    public static function provinces(): array
    {
        return array_keys(self::districtsByProvince());
    }

    /**
     * Tüm ilçe adları, tekrarsız ve alfabetik.
     *
     * Bir seçim kutusu tek başına bir ilçe adı taşıyabildiği için (ad ilin adıyla birlikte
     * gönderilir) kutu, il seçilmeden önce de doldurulabilir olmalıdır: JavaScript kapalı bir
     * tarayıcıda form yine gönderilebilir ve sunucu yine doğru il-ilçe eşleşmesini kendisi
     * doğrular. Burada dönen liste o eksiksiz seçeneklerin kaynağıdır.
     *
     * @return list<string>
     */
    public static function allDistricts(): array
    {
        $all = array_unique(array_merge(...array_values(self::districtsByProvince())));
        usort($all, static fn (string $a, string $b): int => [self::fold($a), $a] <=> [self::fold($b), $b]);

        return $all;
    }

    /**
     * @return list<string> Seçilen ilin ilçeleri; il bilinmiyorsa boş liste.
     */
    public static function districtsOf(?string $province): array
    {
        $key = self::normalizeProvince($province);

        return null === $key ? [] : (self::districtsByProvince()[$key] ?? []);
    }

    public static function hasProvince(?string $province): bool
    {
        return null !== self::normalizeProvince($province);
    }

    /**
     * İlçenin seçilen ile ait olduğunu doğrular; formun gönderdiği değere güvenilmez.
     *
     * İki ayrı ad eşlemesi gerekir: il adı katalogdaki 81 il arasında, ilçe adı yalnızca seçilen
     * ilin ilçeleri arasında aranır. "Merkez" gibi bir ilçe adı ikisinde de geçerli olduğu için
     * ikisini aynı listeden aramak, Adana'yı seçip Şehitkamil yazan bir formu sessizce kabul
     * ederdi.
     */
    public static function hasDistrict(?string $province, ?string $district): bool
    {
        return null !== self::normalizeDistrict($province, $district);
    }

    /**
     * İl adını katalogdaki yazıma eşler; bilinmeyen bir ad için null döner, böylece "Adana"
     * yazan bir form "adan" yazan bir formla aynı kayda gider ve olmayan bir il kaydedilemez.
     */
    public static function normalizeProvince(?string $name): ?string
    {
        return self::match(array_keys(self::districtsByProvince()), $name);
    }

    public static function normalizeDistrict(?string $province, ?string $district): ?string
    {
        return self::match(self::districtsOf($province), $district);
    }

    /**
     * @param list<string> $known
     */
    private static function match(array $known, ?string $name): ?string
    {
        $candidate = null === $name ? '' : trim($name);
        if ('' === $candidate) {
            return null;
        }

        foreach ($known as $catalogued) {
            if (self::sameName($catalogued, $candidate)) {
                return $catalogued;
            }
        }

        return null;
    }

    /**
     * Türkçe'ye duyarlı, büyük/küçük harf duyarsız eşitlik: "şırnak" ve "ŞIRNAK" aynı il.
     */
    private static function sameName(string $known, string $candidate): bool
    {
        return self::fold($known) === self::fold($candidate);
    }

    private static function fold(string $value): string
    {
        // `mb_strtolower` küçük `İ` harfini Türkçe kurallı biçimde `i` + birleşen nokta olarak
        // indirger; o birleşen nokta atılmazsa "İstanbul" ile "Istanbul" farklı iki ad olur.
        return strtr(mb_strtolower($value, 'UTF-8'), [
            'ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c', 'â' => 'a', 'î' => 'i', 'û' => 'u',
            "\u{0307}" => '',
        ]);
    }

    /** @return array<string, list<string>> */
    private static function catalog(): array
    {
        return [
            'Adana' => ['Aladağ', 'Ceyhan', 'Çukurova', 'Feke', 'İmamoğlu', 'Karaisalı', 'Karataş', 'Kozan', 'Pozantı', 'Saimbeyli', 'Sarıçam', 'Seyhan', 'Tufanbeyli', 'Yumurtalık', 'Yüreğir'],
            'Adıyaman' => ['Besni', 'Çelikhan', 'Gerger', 'Gölbaşı', 'Kahta', 'Merkez', 'Samsat', 'Sincik', 'Tut'],
            'Afyonkarahisar' => ['Başmakçı', 'Bayat', 'Bolvadin', 'Çay', 'Çobanlar', 'Dazkırı', 'Dinar', 'Emirdağ', 'Evciler', 'Hocalar', 'İhsaniye', 'İscehisar', 'Kızılören', 'Merkez', 'Sandıklı', 'Sinanpaşa', 'Şuhut', 'Sultandağı'],
            'Ağrı' => ['Diyadin', 'Doğubayazıt', 'Eleşkirt', 'Hamur', 'Merkez', 'Patnos', 'Taşlıçay', 'Tutak'],
            'Aksaray' => ['Ağaçören', 'Eskil', 'Gülağaç', 'Güzelyurt', 'Merkez', 'Ortaköy', 'Sarıyahşi', 'Sultanhanı'],
            'Amasya' => ['Göynücek', 'Gümüşhacıköy', 'Hamamözü', 'Merkez', 'Merzifon', 'Suluova', 'Taşova'],
            'Ankara' => ['Akyurt', 'Altındağ', 'Ayaş', 'Bala', 'Beypazarı', 'Çamlıdere', 'Çankaya', 'Çubuk', 'Elmadağ', 'Etimesgut', 'Evren', 'Gölbaşı', 'Güdül', 'Haymana', 'Kahramankazan', 'Kalecik', 'Keçiören', 'Kızılcahamam', 'Mamak', 'Nallıhan', 'Polatlı', 'Pursaklar', 'Şereflikoçhisar', 'Sincan', 'Yenimahalle'],
            'Antalya' => ['Akseki', 'Aksu', 'Alanya', 'Demre', 'Döşemealtı', 'Elmalı', 'Finike', 'Gazipaşa', 'Gündoğmuş', 'İbradı', 'Kaş', 'Kemer', 'Kepez', 'Konyaaltı', 'Korkuteli', 'Kumluca', 'Manavgat', 'Muratpaşa', 'Serik'],
            'Ardahan' => ['Çıldır', 'Damal', 'Göle', 'Hanak', 'Merkez', 'Posof'],
            'Artvin' => ['Ardanuç', 'Arhavi', 'Borçka', 'Hopa', 'Kemalpaşa', 'Merkez', 'Murgul', 'Şavşat', 'Yusufeli'],
            'Aydın' => ['Bozdoğan', 'Buharkent', 'Çine', 'Didim', 'Efeler', 'Germencik', 'İncirliova', 'Karacasu', 'Karpuzlu', 'Koçarlı', 'Köşk', 'Kuşadası', 'Kuyucak', 'Nazilli', 'Söke', 'Sultanhisar', 'Yenipazar'],
            'Balıkesir' => ['Altıeylül', 'Ayvalık', 'Balya', 'Bandırma', 'Bigadiç', 'Burhaniye', 'Dursunbey', 'Edremit', 'Erdek', 'Gömeç', 'Gönen', 'Havran', 'İvrindi', 'Karesi', 'Kepsut', 'Manyas', 'Marmara', 'Savaştepe', 'Sındırgı', 'Susurluk'],
            'Bartın' => ['Amasra', 'Kurucaşile', 'Merkez', 'Ulus'],
            'Batman' => ['Beşiri', 'Gercüş', 'Hasankeyf', 'Kozluk', 'Merkez', 'Sason'],
            'Bayburt' => ['Aydıntepe', 'Demirözü', 'Merkez'],
            'Bilecik' => ['Bozüyük', 'Gölpazarı', 'İnhisar', 'Merkez', 'Osmaneli', 'Pazaryeri', 'Söğüt', 'Yenipazar'],
            'Bingöl' => ['Adaklı', 'Genç', 'Karlıova', 'Kiğı', 'Merkez', 'Solhan', 'Yayladere', 'Yedisu'],
            'Bitlis' => ['Adilcevaz', 'Ahlat', 'Güroymak', 'Hizan', 'Merkez', 'Mutki', 'Tatvan'],
            'Bolu' => ['Dörtdivan', 'Gerede', 'Göynük', 'Kıbrıscık', 'Mengen', 'Merkez', 'Mudurnu', 'Seben', 'Yeniçağa'],
            'Burdur' => ['Ağlasun', 'Altınyayla', 'Bucak', 'Çavdır', 'Çeltikçi', 'Gölhisar', 'Karamanlı', 'Kemer', 'Merkez', 'Tefenni', 'Yeşilova'],
            'Bursa' => ['Büyükorhan', 'Gemlik', 'Gürsu', 'Harmancık', 'İnegöl', 'İznik', 'Karacabey', 'Keles', 'Kestel', 'Mudanya', 'Mustafakemalpaşa', 'Nilüfer', 'Orhaneli', 'Orhangazi', 'Osmangazi', 'Yenişehir', 'Yıldırım'],
            'Çanakkale' => ['Ayvacık', 'Bayramiç', 'Biga', 'Bozcaada', 'Çan', 'Eceabat', 'Ezine', 'Gelibolu', 'Gökçeada', 'Lapseki', 'Merkez', 'Yenice'],
            'Çankırı' => ['Atkaracalar', 'Bayramören', 'Çerkeş', 'Eldivan', 'Ilgaz', 'Kızılırmak', 'Korgun', 'Kurşunlu', 'Merkez', 'Orta', 'Şabanözü', 'Yapraklı'],
            'Çorum' => ['Alaca', 'Bayat', 'Boğazkale', 'Dodurga', 'İskilip', 'Kargı', 'Laçin', 'Mecitözü', 'Merkez', 'Oğuzlar', 'Ortaköy', 'Osmancık', 'Sungurlu', 'Uğurludağ'],
            'Denizli' => ['Acıpayam', 'Babadağ', 'Baklan', 'Bekilli', 'Beyağaç', 'Bozkurt', 'Buldan', 'Çal', 'Çameli', 'Çardak', 'Çivril', 'Güney', 'Honaz', 'Kale', 'Merkezefendi', 'Pamukkale', 'Sarayköy', 'Serinhisar', 'Tavas'],
            'Diyarbakır' => ['Bağlar', 'Bismil', 'Çermik', 'Çınar', 'Çüngüş', 'Dicle', 'Eğil', 'Ergani', 'Hani', 'Hazro', 'Kayapınar', 'Kocaköy', 'Kulp', 'Lice', 'Silvan', 'Sur', 'Yenişehir'],
            'Düzce' => ['Akçakoca', 'Çilimli', 'Cumayeri', 'Gölyaka', 'Gümüşova', 'Kaynaşlı', 'Merkez', 'Yığılca'],
            'Edirne' => ['Enez', 'Havsa', 'İpsala', 'Keşan', 'Lalapaşa', 'Meriç', 'Merkez', 'Süloğlu', 'Uzunköprü'],
            'Elazığ' => ['Ağın', 'Alacakaya', 'Arıcak', 'Baskil', 'Karakoçan', 'Keban', 'Kovancılar', 'Maden', 'Merkez', 'Palu', 'Sivrice'],
            'Erzincan' => ['Çayırlı', 'İliç', 'Kemah', 'Kemaliye', 'Merkez', 'Otlukbeli', 'Refahiye', 'Tercan', 'Üzümlü'],
            'Erzurum' => ['Aşkale', 'Aziziye', 'Çat', 'Hınıs', 'Horasan', 'İspir', 'Karaçoban', 'Karayazı', 'Köprüköy', 'Narman', 'Oltu', 'Olur', 'Palandöken', 'Pasinler', 'Pazaryolu', 'Şenkaya', 'Tekman', 'Tortum', 'Uzundere', 'Yakutiye'],
            'Eskişehir' => ['Alpu', 'Beylikova', 'Çifteler', 'Günyüzü', 'Han', 'İnönü', 'Mahmudiye', 'Mihalgazi', 'Mihalıççık', 'Odunpazarı', 'Sarıcakaya', 'Seyitgazi', 'Sivrihisar', 'Tepebaşı'],
            'Gaziantep' => ['Araban', 'İslahiye', 'Karkamış', 'Nizip', 'Nurdağı', 'Oğuzeli', 'Şahinbey', 'Şehitkamil', 'Yavuzeli'],
            'Giresun' => ['Alucra', 'Bulancak', 'Çamoluk', 'Çanakçı', 'Dereli', 'Doğankent', 'Espiye', 'Eynesil', 'Görele', 'Güce', 'Keşap', 'Merkez', 'Piraziz', 'Şebinkarahisar', 'Tirebolu', 'Yağlıdere'],
            'Gümüşhane' => ['Kelkit', 'Köse', 'Kürtün', 'Merkez', 'Şiran', 'Torul'],
            'Hakkâri' => ['Çukurca', 'Derecik', 'Merkez', 'Şemdinli', 'Yüksekova'],
            'Hatay' => ['Altınözü', 'Antakya', 'Arsuz', 'Belen', 'Defne', 'Dörtyol', 'Erzin', 'Hassa', 'İskenderun', 'Kırıkhan', 'Kumlu', 'Payas', 'Reyhanlı', 'Samandağ', 'Yayladağı'],
            'Iğdır' => ['Aralık', 'Karakoyunlu', 'Merkez', 'Tuzluca'],
            'Isparta' => ['Aksu', 'Atabey', 'Eğirdir', 'Gelendost', 'Gönen', 'Keçiborlu', 'Merkez', 'Şarkikaraağaç', 'Senirkent', 'Sütçüler', 'Uluborlu', 'Yalvaç', 'Yenişarbademli'],
            'İstanbul' => ['Adalar', 'Arnavutköy', 'Ataşehir', 'Avcılar', 'Bağcılar', 'Bahçelievler', 'Bakırköy', 'Başakşehir', 'Bayrampaşa', 'Beşiktaş', 'Beykoz', 'Beylikdüzü', 'Beyoğlu', 'Büyükçekmece', 'Çatalca', 'Çekmeköy', 'Esenler', 'Esenyurt', 'Eyüpsultan', 'Fatih', 'Gaziosmanpaşa', 'Güngören', 'Kadıköy', 'Kağıthane', 'Kartal', 'Küçükçekmece', 'Maltepe', 'Pendik', 'Sancaktepe', 'Sarıyer', 'Şile', 'Silivri', 'Şişli', 'Sultanbeyli', 'Sultangazi', 'Tuzla', 'Ümraniye', 'Üsküdar', 'Zeytinburnu'],
            'İzmir' => ['Aliağa', 'Balçova', 'Bayındır', 'Bayraklı', 'Bergama', 'Beydağ', 'Bornova', 'Buca', 'Çeşme', 'Çiğli', 'Dikili', 'Foça', 'Gaziemir', 'Güzelbahçe', 'Karabağlar', 'Karaburun', 'Karşıyaka', 'Kemalpaşa', 'Kınık', 'Kiraz', 'Konak', 'Menderes', 'Menemen', 'Narlıdere', 'Ödemiş', 'Seferihisar', 'Selçuk', 'Tire', 'Torbalı', 'Urla'],
            'Kahramanmaraş' => ['Afşin', 'Andırın', 'Çağlayancerit', 'Dulkadiroğlu', 'Ekinözü', 'Elbistan', 'Göksun', 'Nurhak', 'Onikişubat', 'Pazarcık', 'Türkoğlu'],
            'Karabük' => ['Eflani', 'Eskipazar', 'Merkez', 'Ovacık', 'Safranbolu', 'Yenice'],
            'Karaman' => ['Ayrancı', 'Başyayla', 'Ermenek', 'Kazımkarabekir', 'Merkez', 'Sarıveliler'],
            'Kars' => ['Akyaka', 'Arpaçay', 'Digor', 'Kâğızman', 'Merkez', 'Sarıkamış', 'Selim', 'Susuz'],
            'Kastamonu' => ['Abana', 'Ağlı', 'Araç', 'Azdavay', 'Bozkurt', 'Çatalzeytin', 'Cide', 'Daday', 'Devrekani', 'Doğanyurt', 'Hanönü', 'İhsangazi', 'İnebolu', 'Küre', 'Merkez', 'Pınarbaşı', 'Şenpazar', 'Seydiler', 'Taşköprü', 'Tosya'],
            'Kayseri' => ['Akkışla', 'Bünyan', 'Develi', 'Felahiye', 'Hacılar', 'İncesu', 'Kocasinan', 'Melikgazi', 'Özvatan', 'Pınarbaşı', 'Sarıoğlan', 'Sarız', 'Talas', 'Tomarza', 'Yahyalı', 'Yeşilhisar'],
            'Kilis' => ['Elbeyli', 'Merkez', 'Musabeyli', 'Polateli'],
            'Kırıkkale' => ['Bahşılı', 'Balışeyh', 'Çelebi', 'Delice', 'Karakeçili', 'Keskin', 'Merkez', 'Sulakyurt', 'Yahşihan'],
            'Kırklareli' => ['Babaeski', 'Demirköy', 'Kofçaz', 'Lüleburgaz', 'Merkez', 'Pehlivanköy', 'Pınarhisar', 'Vize'],
            'Kırşehir' => ['Akçakent', 'Akpınar', 'Boztepe', 'Çiçekdağı', 'Kaman', 'Merkez', 'Mucur'],
            'Kocaeli' => ['Başiskele', 'Çayırova', 'Darıca', 'Derince', 'Dilovası', 'Gebze', 'Gölcük', 'İzmit', 'Kandıra', 'Karamürsel', 'Kartepe', 'Körfez'],
            'Konya' => ['Ahırlı', 'Akören', 'Akşehir', 'Altınekin', 'Beyşehir', 'Bozkır', 'Çeltik', 'Cihanbeyli', 'Çumra', 'Derbent', 'Derebucak', 'Doğanhisar', 'Emirgazi', 'Ereğli', 'Güneysınır', 'Hadim', 'Halkapınar', 'Hüyük', 'Ilgın', 'Kadınhanı', 'Karapınar', 'Karatay', 'Kulu', 'Meram', 'Sarayönü', 'Selçuklu', 'Seydişehir', 'Taşkent', 'Tuzlukçu', 'Yalıhüyük', 'Yunak'],
            'Kütahya' => ['Altıntaş', 'Aslanapa', 'Çavdarhisar', 'Domaniç', 'Dumlupınar', 'Emet', 'Gediz', 'Hisarcık', 'Merkez', 'Pazarlar', 'Şaphane', 'Simav', 'Tavşanlı'],
            'Malatya' => ['Akçadağ', 'Arapgir', 'Arguvan', 'Battalgazi', 'Darende', 'Doğanşehir', 'Doğanyol', 'Hekimhan', 'Kale', 'Kuluncak', 'Pütürge', 'Yazıhan', 'Yeşilyurt'],
            'Manisa' => ['Ahmetli', 'Akhisar', 'Alaşehir', 'Demirci', 'Gölmarmara', 'Gördes', 'Kırkağaç', 'Köprübaşı', 'Kula', 'Salihli', 'Sarıgöl', 'Saruhanlı', 'Şehzadeler', 'Selendi', 'Soma', 'Turgutlu', 'Yunusemre'],
            'Mardin' => ['Artuklu', 'Dargeçit', 'Derik', 'Kızıltepe', 'Mazıdağı', 'Midyat', 'Nusaybin', 'Ömerli', 'Savur', 'Yeşilli'],
            'Mersin' => ['Akdeniz', 'Anamur', 'Aydıncık', 'Bozyazı', 'Çamlıyayla', 'Erdemli', 'Gülnar', 'Mezitli', 'Mut', 'Silifke', 'Tarsus', 'Toroslar', 'Yenişehir'],
            'Muğla' => ['Bodrum', 'Dalaman', 'Datça', 'Fethiye', 'Kavaklıdere', 'Köyceğiz', 'Marmaris', 'Menteşe', 'Milas', 'Ortaca', 'Seydikemer', 'Ula', 'Yatağan'],
            'Muş' => ['Bulanık', 'Hasköy', 'Korkut', 'Malazgirt', 'Merkez', 'Varto'],
            'Nevşehir' => ['Acıgöl', 'Avanos', 'Derinkuyu', 'Gülşehir', 'Hacıbektaş', 'Kozaklı', 'Merkez', 'Ürgüp'],
            'Niğde' => ['Altunhisar', 'Bor', 'Çamardı', 'Çiftlik', 'Merkez', 'Ulukışla'],
            'Ordu' => ['Akkuş', 'Altınordu', 'Aybastı', 'Çamaş', 'Çatalpınar', 'Çaybaşı', 'Fatsa', 'Gölköy', 'Gülyalı', 'Gürgentepe', 'İkizce', 'Kabadüz', 'Kabataş', 'Korgan', 'Kumru', 'Mesudiye', 'Perşembe', 'Ulubey', 'Ünye'],
            'Osmaniye' => ['Bahçe', 'Düziçi', 'Hasanbeyli', 'Kadirli', 'Merkez', 'Sumbas', 'Toprakkale'],
            'Rize' => ['Ardeşen', 'Çamlıhemşin', 'Çayeli', 'Derepazarı', 'Fındıklı', 'Güneysu', 'Hemşin', 'İkizdere', 'İyidere', 'Kalkandere', 'Merkez', 'Pazar'],
            'Sakarya' => ['Adapazarı', 'Akyazı', 'Arifiye', 'Erenler', 'Ferizli', 'Geyve', 'Hendek', 'Karapürçek', 'Karasu', 'Kaynarca', 'Kocaali', 'Pamukova', 'Sapanca', 'Serdivan', 'Söğütlü', 'Taraklı'],
            'Samsun' => ['19 Mayıs', 'Alaçam', 'Asarcık', 'Atakum', 'Ayvacık', 'Bafra', 'Canik', 'Çarşamba', 'Havza', 'İlkadım', 'Kavak', 'Ladik', 'Salıpazarı', 'Tekkeköy', 'Terme', 'Vezirköprü', 'Yakakent'],
            'Şanlıurfa' => ['Akçakale', 'Birecik', 'Bozova', 'Ceylanpınar', 'Eyyübiye', 'Halfeti', 'Haliliye', 'Harran', 'Hilvan', 'Karaköprü', 'Siverek', 'Suruç', 'Viranşehir'],
            'Siirt' => ['Baykan', 'Eruh', 'Kurtalan', 'Merkez', 'Pervari', 'Şirvan', 'Tillo'],
            'Sinop' => ['Ayancık', 'Boyabat', 'Dikmen', 'Durağan', 'Erfelek', 'Gerze', 'Merkez', 'Saraydüzü', 'Türkeli'],
            'Şırnak' => ['Beytüşşebap', 'Cizre', 'Güçlükonak', 'İdil', 'Merkez', 'Silopi', 'Uludere'],
            'Sivas' => ['Akıncılar', 'Altınyayla', 'Divriği', 'Doğanşar', 'Gemerek', 'Gölova', 'Gürün', 'Hafik', 'İmranlı', 'Kangal', 'Koyulhisar', 'Merkez', 'Şarkışla', 'Suşehri', 'Ulaş', 'Yıldızeli', 'Zara'],
            'Tekirdağ' => ['Çerkezköy', 'Çorlu', 'Ergene', 'Hayrabolu', 'Kapaklı', 'Malkara', 'Marmaraereğlisi', 'Muratlı', 'Saray', 'Şarköy', 'Süleymanpaşa'],
            'Tokat' => ['Almus', 'Artova', 'Başçiftlik', 'Erbaa', 'Merkez', 'Niksar', 'Pazar', 'Reşadiye', 'Sulusaray', 'Turhal', 'Yeşilyurt', 'Zile'],
            'Trabzon' => ['Akçaabat', 'Araklı', 'Arsin', 'Beşikdüzü', 'Çarşıbaşı', 'Çaykara', 'Dernekpazarı', 'Düzköy', 'Hayrat', 'Köprübaşı', 'Maçka', 'Of', 'Ortahisar', 'Şalpazarı', 'Sürmene', 'Tonya', 'Vakfıkebir', 'Yomra'],
            'Tunceli' => ['Çemişgezek', 'Hozat', 'Mazgirt', 'Merkez', 'Nazımiye', 'Ovacık', 'Pertek', 'Pülümür'],
            'Uşak' => ['Banaz', 'Eşme', 'Karahallı', 'Merkez', 'Sivaslı', 'Ulubey'],
            'Van' => ['Bahçesaray', 'Başkale', 'Çaldıran', 'Çatak', 'Edremit', 'Erciş', 'Gevaş', 'Gürpınar', 'İpekyolu', 'Muradiye', 'Özalp', 'Saray', 'Tuşba'],
            'Yalova' => ['Altınova', 'Armutlu', 'Çiftlikköy', 'Çınarcık', 'Merkez', 'Termal'],
            'Yozgat' => ['Akdağmadeni', 'Aydıncık', 'Boğazlıyan', 'Çandır', 'Çayıralan', 'Çekerek', 'Kadışehri', 'Merkez', 'Saraykent', 'Sarıkaya', 'Şefaatli', 'Sorgun', 'Yenifakılı', 'Yerköy'],
            'Zonguldak' => ['Alaplı', 'Çaycuma', 'Devrek', 'Ereğli', 'Gökçebey', 'Kilimli', 'Kozlu', 'Merkez'],
        ];
    }
}
