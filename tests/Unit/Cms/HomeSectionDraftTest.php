<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cms;

use App\Module\Cms\HomeSectionDraft;
use App\Module\Cms\HomeSectionType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The section form is read back out of the request and one named action is applied to it.
 *
 * These are the two failure modes a form has to survive: a submission that leaves something out,
 * and a submission that asks for an edit the section does not have. The second is the security
 * relevant one, so the `_target` cases below are about refusing a path the section type never
 * declared rather than about a convenient shorthand.
 */
final class HomeSectionDraftTest extends TestCase
{
    private HomeSectionDraft $drafts;

    protected function setUp(): void
    {
        $this->drafts = new HomeSectionDraft();
    }

    public function testAStoredConfigurationBecomesAnEditableDraftWithoutChangingIt(): void
    {
        $stored = ['items' => ['Birinci', 'İkinci']];

        $draft = $this->drafts->fromConfiguration(HomeSectionType::Marquee, $stored);

        self::assertSame([['text' => 'Birinci'], ['text' => 'İkinci']], $draft['items']);
        self::assertSame(['items' => ['Birinci', 'İkinci']], $stored);
    }

    public function testANewSectionStartsFromTheShapesItsOwnTypeDeclares(): void
    {
        self::assertSame(['text' => ''], $this->drafts->fromConfiguration(HomeSectionType::AnnouncementBar, null));
        self::assertSame(['limit' => 3], $this->drafts->fromConfiguration(HomeSectionType::BlogFeed, null));
        self::assertSame(['slugs' => []], $this->drafts->fromConfiguration(HomeSectionType::BrandStrip, null));
        self::assertSame(
            [['title' => '', 'slugs' => []]],
            $this->drafts->fromConfiguration(HomeSectionType::ProductTabs, null)['tabs'],
        );
    }

    public function testRowsAreReadBackInTheOrderTheyWereSubmitted(): void
    {
        $draft = $this->drafts->fromRequest(HomeSectionType::Marquee, [
            'items_count' => '3',
            'items_0_text' => 'Birinci',
            'items_1_text' => 'İkinci',
            'items_2_text' => 'Üçüncü',
        ], ['items' => [['text' => '']]]);

        self::assertSame(
            [['text' => 'Birinci'], ['text' => 'İkinci'], ['text' => 'Üçüncü']],
            $draft['items'],
        );
    }

    /** @return iterable<string, array{string, int, list<string>}> */
    public static function rowActions(): iterable
    {
        yield 'add' => [HomeSectionDraft::ADD_ROW, 0, ['Birinci', 'İkinci', 'Üçüncü', '']];
        yield 'remove first' => [HomeSectionDraft::REMOVE_ROW, 0, ['İkinci', 'Üçüncü']];
        yield 'remove second' => [HomeSectionDraft::REMOVE_ROW, 1, ['Birinci', 'Üçüncü']];
        yield 'move up' => [HomeSectionDraft::MOVE_ROW_UP, 1, ['İkinci', 'Birinci', 'Üçüncü']];
        yield 'move down' => [HomeSectionDraft::MOVE_ROW_DOWN, 0, ['İkinci', 'Birinci', 'Üçüncü']];
        yield 'out of range' => [HomeSectionDraft::MOVE_ROW_DOWN, 9, ['Birinci', 'İkinci', 'Üçüncü']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('rowActions')]
    public function testEachRowActionProducesTheListTheAdministratorAskedFor(string $action, int $index, array $expected): void
    {
        $draft = $this->drafts->fromRequest(HomeSectionType::Marquee, [
            'items_count' => '3',
            'items_0_text' => 'Birinci',
            'items_1_text' => 'İkinci',
            'items_2_text' => 'Üçüncü',
            '_action' => $action,
            '_index' => (string) $index,
        ], ['items' => [['text' => 'Birinci'], ['text' => 'İkinci'], ['text' => 'Üçüncü']]]);

        self::assertSame($expected, array_map(static fn (array $row): string => (string) $row['text'], $draft['items']));
    }

    public function testTheLastRowCannotBeDeletedBecauseTheDomainRequiresOne(): void
    {
        $draft = $this->drafts->fromRequest(HomeSectionType::Marquee, [
            'items_count' => '1',
            'items_0_text' => 'Tek',
            '_action' => HomeSectionDraft::REMOVE_ROW,
            '_index' => '0',
        ], ['items' => [['text' => 'Tek']]]);

        self::assertSame([['text' => 'Tek']], $draft['items']);
    }

    public function testTheSearchedProductsAreAppendedToTheSelectionInTheOrderTheyWereChosen(): void
    {
        $draft = $this->drafts->fromRequest(HomeSectionType::ProductCarousel, [
            'slugs_count' => '1',
            'slugs_0' => 'mevcut',
            'add' => ['slugs_add' => ['ikinci', 'birinci']],
            '_action' => HomeSectionDraft::ADD_OPTION,
            '_target' => 'slugs',
        ], ['slugs' => ['mevcut']]);

        self::assertSame(['mevcut', 'ikinci', 'birinci'], $draft['slugs']);
    }

    public function testASelectionIsReorderedAndRemovedByItsPosition(): void
    {
        $current = ['slugs' => ['a', 'b', 'c']];
        $posted = ['slugs_count' => '3', 'slugs_0' => 'a', 'slugs_1' => 'b', 'slugs_2' => 'c', '_target' => 'slugs'];

        $moved = $this->drafts->fromRequest(
            HomeSectionType::BrandStrip,
            $posted + ['_action' => HomeSectionDraft::MOVE_OPTION_DOWN, '_index' => '0'],
            $current,
        );
        self::assertSame(['b', 'a', 'c'], $moved['slugs']);

        $removed = $this->drafts->fromRequest(
            HomeSectionType::BrandStrip,
            $posted + ['_action' => HomeSectionDraft::REMOVE_OPTION, '_index' => '1'],
            $current,
        );
        self::assertSame(['a', 'c'], $removed['slugs']);
    }

    public function testEachProductTabKeepsItsOwnSelection(): void
    {
        $draft = $this->drafts->fromRequest(HomeSectionType::ProductTabs, [
            'tabs_count' => '2',
            'tabs_0_title' => 'Çok satanlar',
            'tabs_0_p_count' => '1',
            'tabs_0_p_0' => 'birinci',
            'tabs_1_title' => 'Yeni gelenler',
            'tabs_1_p_count' => '0',
            'add' => ['tabs_1_p_add' => ['ikinci']],
            '_action' => HomeSectionDraft::ADD_OPTION,
            '_target' => 'tabs_1_p',
        ], ['tabs' => [['title' => '', 'slugs' => []]]]);

        self::assertSame(['birinci'], $draft['tabs'][0]['slugs']);
        self::assertSame(['ikinci'], $draft['tabs'][1]['slugs']);
    }

    /**
     * A `_target` is the one place a request names a key inside the configuration, so a section
     * that has no per-row selection must not accept one — and a per-row section must only accept
     * the shape it actually renders.
     */
    #[DataProvider('refusedTargets')]
    public function testASelectionActionIsRefusedForATargetTheSectionDoesNotHave(HomeSectionType $type, string $target): void
    {
        $current = ['slugs' => ['a', 'b'], 'tabs' => [['title' => 'T', 'slugs' => ['a']]]];

        $draft = $this->drafts->fromRequest($type, [
            'slugs_count' => '2',
            'slugs_0' => 'a',
            'slugs_1' => 'b',
            'tabs_count' => '1',
            'tabs_0_title' => 'T',
            'add' => ['slugs_add' => ['sızdırılmış'], 'tabs_0_p_add' => ['sızdırılmış']],
            '_action' => HomeSectionDraft::ADD_OPTION,
            '_target' => $target,
        ], $current);

        self::assertStringNotContainsString('sızdırılmış', json_encode($draft, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{HomeSectionType, string}> */
    public static function refusedTargets(): iterable
    {
        yield 'no selection at all' => [HomeSectionType::Marquee, 'slugs'];
        yield 'positional target on a flat section' => [HomeSectionType::ProductCarousel, 'tabs_0_p'];
        yield 'nested path' => [HomeSectionType::ProductCarousel, 'tabs][0][slugs'];
        yield 'row outside the rendered range' => [HomeSectionType::ProductTabs, 'tabs_9_p'];
        yield 'unrelated key' => [HomeSectionType::BrandStrip, 'quotes_0'];
    }

    public function testASelectionNeverExceedsTheCeilingTheDomainEnforces(): void
    {
        $chosen = [];
        for ($index = 0; $index < 40; ++$index) {
            $chosen[] = 'slug-'.$index;
        }

        $draft = $this->drafts->fromRequest(HomeSectionType::ProductCarousel, [
            'slugs_count' => '0',
            'add' => ['slugs_add' => $chosen],
            '_action' => HomeSectionDraft::ADD_OPTION,
            '_target' => 'slugs',
        ], ['slugs' => []]);

        self::assertCount(HomeSectionDraft::MAX_OPTIONS, $draft['slugs']);
    }
}
