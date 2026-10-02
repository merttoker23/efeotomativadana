<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cms;

use App\Module\Cms\HomeSectionType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A section type declares its own form: which repeatable rows it has, which fields one row has,
 * which fixed fields sit beside them, and whether it needs a catalogue picker.
 *
 * The admin form is generated from that declaration instead of being written eleven times, which
 * only works while the declaration is complete. These are the properties a new section type would
 * otherwise break silently — a row field with no label, a selection that no draft carries a key
 * for — and each of them would surface as a form that renders but does not submit.
 */
final class HomeSectionTypeTest extends TestCase
{
    /** @return iterable<string, array{HomeSectionType}> */
    public static function types(): iterable
    {
        foreach (HomeSectionType::cases() as $type) {
            yield $type->value => [$type];
        }
    }

    #[DataProvider('types')]
    public function testEverySectionTypeIsReachableFromTheHomepageTemplate(HomeSectionType $type): void
    {
        self::assertFileExists(dirname(__DIR__, 3).'/templates/'.$type->template());
    }

    #[DataProvider('types')]
    public function testEveryDeclaredFieldHasALabel(HomeSectionType $type): void
    {
        $labels = $type->fieldLabels();
        self::assertNotEmpty($labels, $type->value.' labels nothing at all.');
        $declared = array_merge(array_keys($type->fields()), $type->rowFields());

        foreach ($declared as $field) {
            self::assertArrayHasKey($field, $labels, $type->value.' has no label for "'.$field.'".');
            self::assertNotSame('', trim($labels[$field]));
        }
    }

    #[DataProvider('types')]
    public function testARowBasedSectionDeclaresAtLeastOneRowFieldAndAFlatOneDeclaresNoRows(HomeSectionType $type): void
    {
        if (null === $type->rowName()) {
            self::assertSame([], $type->rowFields(), $type->value.' declares row fields without a row collection.');

            return;
        }

        self::assertNotEmpty($type->rowFields(), $type->value.' declares a row collection with no fields.');
        self::assertArrayHasKey($type->rowName(), $type->emptyDraft(), $type->value.' has no draft shape for its rows.');
    }

    #[DataProvider('types')]
    public function testASelectionIsDeclaredForEveryKeyTheDomainCanStore(HomeSectionType $type): void
    {
        $draft = $type->emptyDraft();
        $rows = $type->rowName();

        if (null === $type->selection()) {
            self::assertStringNotContainsString('slugs', (string) json_encode($draft), $type->value.' stores slugs with no picker.');

            return;
        }

        // The picker is the only place a slug can come from, and it only knows these three kinds.
        self::assertContains($type->selection(), ['products', 'categories', 'brands']);

        if (null === $rows) {
            self::assertArrayHasKey('slugs', $draft, $type->value.' declares a selection with nowhere to put it.');
            self::assertFalse($type->selectionPerRow(), $type->value.' is not row based, so its selection cannot be per row.');

            return;
        }

        self::assertTrue($type->selectionPerRow(), $type->value.' is row based, so its selection belongs to the row.');
        self::assertArrayHasKey('slugs', $draft[$rows][0], $type->value.' declares a per-row selection with nowhere to put it.');
    }

    #[DataProvider('types')]
    public function testEveryTypeExplainsWhereItAppearsAndWhatItIsCalled(HomeSectionType $type): void
    {
        self::assertNotSame('', trim($type->label()));
        self::assertNotSame('', trim($type->placement()));
    }
}
