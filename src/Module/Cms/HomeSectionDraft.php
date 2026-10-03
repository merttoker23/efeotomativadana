<?php

declare(strict_types=1);

namespace App\Module\Cms;

use App\Module\Catalog\ProductFeedSource;

/**
 * The editable state of one section form, in the stored configuration's own shape.
 *
 * A draft is not a configuration. It may hold an empty headline, a row with nothing in it and an
 * image that has not been chosen yet, all of which the domain refuses; it exists so a form can
 * be rendered for a section that does not exist yet and so a rejected submission can be shown
 * back with the administrator's own words still in it.
 *
 * Every mutation an administrator performs on the page — add a row, delete a row, move a product
 * up, add the three search results they picked — is a request that carries the whole form. This
 * class reads that request back into a draft and then applies the one action it names, so
 * editing needs no session, no hidden server-side state and no partial round trip.
 */
final class HomeSectionDraft
{
    /** The domain refuses longer lists than this, so a form never offers to build one. */
    public const int MAX_ROWS = 20;

    /** A picker is not a catalogue page; the domain's own list ceiling is the control's ceiling. */
    public const int MAX_OPTIONS = 20;

    public const string ADD_ROW = 'add-row';
    public const string REMOVE_ROW = 'remove-row';
    public const string MOVE_ROW_UP = 'move-row-up';
    public const string MOVE_ROW_DOWN = 'move-row-down';
    public const string ADD_OPTION = 'add-option';
    public const string REMOVE_OPTION = 'remove-option';
    public const string MOVE_OPTION_UP = 'move-option-up';
    public const string MOVE_OPTION_DOWN = 'move-option-down';

    /**
     * The draft a stored section starts from.
     *
     * A stored configuration always validates, so it already has the right shape; it is copied
     * rather than used directly so that editing can never mutate the entity's array in place
     * before the administrator has saved.
     *
     * @param array<string, mixed>|null $configuration
     *
     * @return array<string, mixed>
     */
    public function fromConfiguration(HomeSectionType $type, ?array $configuration): array
    {
        $draft = $type->emptyDraft();
        if (null === $configuration) {
            return $draft;
        }

        foreach ($type->fields() as $field => $kind) {
            if (\array_key_exists($field, $configuration)) {
                $draft[$field] = 'number' === $kind
                    ? (int) $configuration[$field]
                    : (string) $configuration[$field];
            }
        }

        $rows = $type->rowName();
        if (null !== $rows) {
            $draft[$rows] = $this->normalizeRows($type, $configuration[$rows] ?? []);
        }

        $selection = $type->selection();
        if (null !== $selection) {
            if ($type->selectionPerRow()) {
                /** @var list<array<string, mixed>> $stored */
                $stored = \is_array($configuration[$rows ?? ''] ?? null) ? array_values($configuration[$rows ?? '']) : [];
                foreach ($draft[$rows] as $index => $row) {
                    $draft[$rows][$index]['slugs'] = $this->normalizeSlugs($stored[$index]['slugs'] ?? []);
                }
            } else {
                $draft['slugs'] = $this->normalizeSlugs($configuration['slugs'] ?? []);
            }
        }

        return $draft;
    }

    /**
     * The draft a submitted form describes, after the one action it asked for.
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $current the draft the form was rendered from, used as the
     *                                      fallback for a collection the request did not carry
     *
     * @return array<string, mixed>
     */
    public function fromRequest(HomeSectionType $type, array $fields, array $current): array
    {
        $draft = $current;

        foreach ($type->fields() as $field => $kind) {
            $draft[$field] = 'number' === $kind
                ? self::number($fields, $field, 3)
                : self::text($fields, $field);
        }

        $rows = $type->rowName();
        if (null !== $rows) {
            $draft[$rows] = $this->readRows($type, $rows, $fields, $current[$rows] ?? []);
        }

        $selection = $type->selection();
        if (null !== $selection && !$type->selectionPerRow()) {
            $draft['slugs'] = $this->readSlugs('slugs', $fields);
        }

        $action = self::text($fields, '_action');
        if ('' === $action) {
            return $draft;
        }

        $target = $this->selectionTarget($type, self::text($fields, '_target'));
        $index = max(0, self::number($fields, '_index', 0));
        $editsSelection = \in_array($action, [self::ADD_OPTION, self::REMOVE_OPTION, self::MOVE_OPTION_UP, self::MOVE_OPTION_DOWN], true);

        if (null !== $rows) {
            $collection = \is_array($draft[$rows]) ? array_values($draft[$rows]) : [];
            $draft[$rows] = match ($action) {
                self::ADD_ROW => $this->pushRow($type, $rows, $collection),
                self::REMOVE_ROW => $this->cutRow($collection, $index),
                self::MOVE_ROW_UP => $this->shiftRow($collection, $index, -1),
                self::MOVE_ROW_DOWN => $this->shiftRow($collection, $index, 1),
                default => $collection,
            };
        }

        if (null !== $target && $editsSelection) {
            $this->editSelection($draft, $rows, $target, $action, $index, $fields);
        }

        return $draft;
    }

    /**
     * A submitted scalar, read the way a form control's value always arrives: as a string, and
     * trimmed because a text field happily submits whitespace for a field nobody typed in.
     *
     * @param array<string, mixed> $fields
     */
    private static function text(array $fields, string $key): string
    {
        $value = $fields[$key] ?? '';

        return mb_substr(\is_scalar($value) ? trim((string) $value) : '', 0, 500);
    }

    /**
     * A submitted count, read as a non-negative integer however the browser spelled it.
     *
     * @param array<string, mixed> $fields
     */
    private static function number(array $fields, string $key, int $default): int
    {
        $value = $fields[$key] ?? null;

        return \is_scalar($value) && is_numeric((string) $value) ? (int) $value : $default;
    }

    /**
     * @param list<mixed> $collection
     *
     * @return list<mixed>
     */
    private function pushRow(HomeSectionType $type, string $rows, array $collection): array
    {
        if (self::MAX_ROWS <= \count($collection)) {
            return $collection;
        }
        /** @var list<mixed> $blank */
        $blank = array_values($type->emptyDraft()[$rows] ?? []);
        /** @var mixed $row */
        $row = $blank[0] ?? [];
        $collection[] = \is_array($row) ? $row : [];

        return $collection;
    }

    /**
     * @param list<mixed> $collection
     *
     * @return list<mixed>
     */
    private function cutRow(array $collection, int $index): array
    {
        if (!isset($collection[$index]) || 1 >= \count($collection)) {
            return $collection;
        }
        unset($collection[$index]);

        return array_values($collection);
    }

    /**
     * @param list<mixed> $collection
     *
     * @return list<mixed>
     */
    private function shiftRow(array $collection, int $index, int $offset): array
    {
        $target = $index + $offset;
        if (!isset($collection[$index], $collection[$target])) {
            return $collection;
        }
        [$collection[$index], $collection[$target]] = [$collection[$target], $collection[$index]];

        return $collection;
    }

    /**
     * Add, delete or reorder inside one catalogue picker.
     *
     * The picker is addressed by name, never by a path assembled from the request: `_target` is
     * matched against the shape this section type actually has and anything else is refused, so a
     * crafted `_target` cannot reach a key the section does not have.
     *
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $fields
     */
    private function editSelection(array &$draft, ?string $rows, string $target, string $action, int $index, array $fields): void
    {
        $position = $this->selectionPosition($target);
        $prefix = $target;

        if (null === $position) {
            $slugs = \is_array($draft['slugs'] ?? null) ? array_values($draft['slugs']) : [];
        } else {
            if (null === $rows || !isset($draft[$rows][$position]) || !\is_array($draft[$rows][$position])) {
                return;
            }
            $prefix = $rows.'_'.$position.'_p';
            $existing = $draft[$rows][$position]['slugs'] ?? [];
            $slugs = \is_array($existing) ? array_values($existing) : [];
        }

        $added = \is_array($fields['add'] ?? null) ? $fields['add'] : [];
        $chosen = \is_array($added[$prefix.'_add'] ?? null) ? $added[$prefix.'_add'] : [];
        $slugs = match ($action) {
            self::ADD_OPTION => $this->appendOptions($slugs, $chosen),
            self::REMOVE_OPTION => $this->dropOption($slugs, $index),
            self::MOVE_OPTION_UP => $this->shiftOption($slugs, $index, -1),
            self::MOVE_OPTION_DOWN => $this->shiftOption($slugs, $index, 1),
            default => $slugs,
        };

        if (null === $position) {
            $draft['slugs'] = $slugs;

            return;
        }
        $draft[$rows][$position]['slugs'] = $slugs;
    }

    /**
     * @param list<mixed> $slugs
     * @param mixed       $chosen
     *
     * @return list<mixed>
     */
    private function appendOptions(array $slugs, mixed $chosen): array
    {
        if (!\is_array($chosen)) {
            return $slugs;
        }
        foreach ($chosen as $slug) {
            if (!\is_string($slug) || \in_array($slug, $slugs, true)) {
                continue;
            }
            if (self::MAX_OPTIONS <= \count($slugs)) {
                break;
            }
            $slugs[] = $slug;
        }

        return $slugs;
    }

    /**
     * @param list<mixed> $slugs
     *
     * @return list<mixed>
     */
    private function dropOption(array $slugs, int $index): array
    {
        if (!isset($slugs[$index])) {
            return $slugs;
        }
        unset($slugs[$index]);

        return array_values($slugs);
    }

    /**
     * @param list<mixed> $slugs
     *
     * @return list<mixed>
     */
    private function shiftOption(array $slugs, int $index, int $offset): array    {
        $target = $index + $offset;
        if (!isset($slugs[$index], $slugs[$target])) {
            return $slugs;
        }
        [$slugs[$index], $slugs[$target]] = [$slugs[$target], $slugs[$index]];

        return $slugs;
    }

    /**
     * The row index a `_target` names, or null for the section's own picker.
     *
     * Only a per-row section such as the product tabs has a positional target at all, and only
     * within the range the form rendered.
     */
    private function selectionPosition(string $target): ?int
    {
        if (1 !== preg_match('~^tabs_(\d+)_p$~', $target, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    private function selectionTarget(HomeSectionType $type, string $target): ?string
    {
        if ('' === $target || null === $type->selection()) {
            return null;
        }
        if (!$type->selectionPerRow()) {
            return 'slugs' === $target ? 'slugs' : null;
        }

        return null === $this->selectionPosition($target) ? null : $target;
    }

    /**
     * @return list<mixed>
     */
    private function normalizeRows(HomeSectionType $type, mixed $rows): array
    {
        $normalized = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if ([] !== $row = $this->normalizeRow($type, $row)) {
                $normalized[] = $row;
            }
        }

        if ([] === $normalized) {
            /** @var list<mixed> $blank */
            $blank = array_values($type->emptyDraft()[$type->rowName() ?? ''] ?? []);

            return $blank;
        }

        return \array_slice($normalized, 0, self::MAX_ROWS);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeRow(HomeSectionType $type, mixed $row): array
    {
        $fields = $type->rowFields();
        if (!\is_array($row) && 1 === \count($fields)) {
            // The marquee stores its words as plain strings; a one-field row is that same thing
            // written out, so the form and the configuration still speak the same shape.
            $row = [$fields[0] => \is_scalar($row) ? (string) $row : ''];
        }
        if (!\is_array($row)) {
            return [];
        }

        $normalized = [];
        foreach ($fields as $field) {
            $normalized[$field] = mb_substr(trim((string) ($row[$field] ?? '')), 0, 500);
        }
        if ($type->selectionPerRow()) {
            $normalized['slugs'] = $this->normalizeSlugs($row['slugs'] ?? []);
        }

        return $this->normalizeSources($type, $normalized);
    }

    /**
     * A row's closed-set fields, read as one of the values this store has.
     *
     * A source arrives from a form and from storage alike, and neither is allowed to carry a value
     * outside the four. Reading it through {@see ProductFeedSource} rather than trimming it means
     * the form always has a selected option — including for a row stored before the field existed,
     * which is shown as the manual source it always was.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function normalizeSources(HomeSectionType $type, array $row): array
    {
        $source = null;
        foreach (array_keys($row) as $field) {
            if ($type->isSourceField((string) $field)) {
                $source = ProductFeedSource::normalize($row[$field]);
                $row[$field] = $source->value;
            }
        }

        // An automatic source names its own products, so a list left over from when the row was the
        // manual one is dropped here rather than being carried into a save the domain would refuse.
        // Switching back is the administrator's next move, and the list they lost is one they can
        // pick again.
        if (null !== $source && !$source->isManual()) {
            $row['slugs'] = [];
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $fields
     * @param mixed                $current
     *
     * @return list<mixed>
     */
    private function readRows(HomeSectionType $type, string $rows, array $fields, mixed $current): array
    {
        $count = min(self::MAX_ROWS, max(0, self::number($fields, $rows.'_count', 0)));
        $read = [];
        for ($index = 0; $index < $count; ++$index) {
            $row = [];
            foreach ($type->rowFields() as $field) {
                $row[$field] = self::text($fields, $rows.'_'.$index.'_'.$field);
            }
            $read[] = $this->normalizeSources(
                $type,
                $type->selectionPerRow()
                    ? [...$row, 'slugs' => $this->readSlugs($rows.'_'.$index.'_p', $fields)]
                    : $row,
            );
        }

        return [] === $read && \is_array($current) ? array_values($current) : $read;
    }

    /**
     * The picker a form posted back, one hidden input per selected row.
     *
     * A `<select multiple>` is not enough: a browser submits its options in document order, not in
     * the order the administrator arranged them, and the order of a carousel or a category panel
     * is the whole content decision. One indexed input per row is what makes the order survive a
     * round trip.
     *
     * @param array<string, mixed> $fields
     *
     * @return list<mixed>
     */
    private function readSlugs(string $prefix, array $fields): array
    {
        $count = min(self::MAX_OPTIONS, max(0, self::number($fields, $prefix.'_count', 0)));
        $slugs = [];
        for ($index = 0; $index < $count; ++$index) {
            $slug = self::text($fields, $prefix.'_'.$index);
            if ('' !== $slug && !\in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /** @return list<mixed> */
    private function normalizeSlugs(mixed $slugs): array
    {
        $normalized = [];
        foreach (\is_array($slugs) ? $slugs : [] as $slug) {
            if (\is_string($slug) && '' !== $slug && !\in_array($slug, $normalized, true)) {
                $normalized[] = $slug;
            }
        }

        return \array_slice($normalized, 0, self::MAX_OPTIONS);
    }
}
