<?php

declare(strict_types=1);

namespace App\Module\Cms;

use Symfony\Component\HttpFoundation\FileBag;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Turns a filled-in section draft into a configuration the domain will accept.
 *
 * This is the only way a `HomeSection` ever gets a new configuration, and it accepts exactly the
 * fields the section type declares for it. There is no configuration parameter and no free-form
 * document anywhere in the path, so a submission cannot carry a key, a value or a template the
 * form did not offer — the failure mode of a raw JSON box is not narrowed here, it is absent.
 *
 * Every value is still handed to {@see SectionConfiguration} afterwards. This class decides what
 * a field means; the domain decides what is allowed.
 */
final readonly class HomeSectionInput
{
    public function __construct(private CmsMediaStorage $media) {}

    /**
     * @param array<string, mixed> $draft
     *
     * @return array<string, mixed>
     */
    public function configuration(HomeSectionType $type, array $draft, FileBag $files): array
    {
        $rows = $type->rowName();
        $selection = $type->selection();

        if (null === $rows) {
            /** @var array<string, mixed> $configuration */
            $configuration = [];
            foreach ($type->fields() as $field => $kind) {
                $configuration[$field] = 'number' === $kind
                    ? $this->limit((int) ($draft[$field] ?? 3))
                    : mb_substr(trim((string) ($draft[$field] ?? '')), 0, 500);
            }
            if (null !== $selection) {
                $configuration['slugs'] = $this->slugs($draft['slugs'] ?? []);
            }

            return SectionConfiguration::validate($type, $configuration);
        }

        $configuration = [];
        foreach ($this->rows($type, $rows, $draft, $files) as $index => $row) {
            if (HomeSectionType::Marquee === $type) {
                $configuration['items'][] = trim((string) ($row['text'] ?? ''));

                continue;
            }
            $entry = [];
            foreach ($type->rowFields() as $field) {
                $entry[$field] = \in_array($field, ['image', 'mobileImage'], true)
                    ? $this->image($rows, $index, (string) ($row[$field] ?? ''), $files, $field)
                    : mb_substr(trim((string) ($row[$field] ?? '')), 0, 500);
            }
            if ($type->selectionPerRow()) {
                $entry['slugs'] = $this->slugs($row['slugs'] ?? []);
            }
            $configuration[$rows][] = $entry;
        }

        if (HomeSectionType::Marquee === $type) {
            $configuration['items'] = array_values(array_filter(
                $configuration['items'] ?? [],
                static fn (string $item): bool => '' !== $item,
            ));
        }

        return SectionConfiguration::validate($type, $configuration);
    }

    /**
     * The draft's rows, with the ones nobody filled in removed.
     *
     * "Add row" leaves a row behind on purpose, so an administrator can compose several at once.
     * A row that stayed completely empty is not a content mistake to complain about; it is a row
     * that was never started, and it is dropped here rather than made the domain's problem.
     *
     * @param array<string, mixed> $draft
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(HomeSectionType $type, string $rows, array $draft, FileBag $files): array
    {
        $filled = [];
        $index = 0;
        foreach (\is_array($draft[$rows] ?? null) ? array_values($draft[$rows]) : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $uploaded = \in_array('image', $type->rowFields(), true) && $this->hasUpload($rows, $index, $files);
            if (HomeSectionType::HeroSlider === $type) {
                $uploaded = $uploaded || $this->hasUpload($rows, $index, $files, 'mobileImage');
            }
            $text = trim(implode('', array_map(
                static fn (string $field): string => $field === 'image' ? '' : (string) ($row[$field] ?? ''),
                $type->rowFields(),
            )));
            $selected = $type->selectionPerRow() && [] !== $this->slugs($row['slugs'] ?? []);
            $hasImage = \in_array('image', $type->rowFields(), true) && '' !== trim((string) ($row['image'] ?? ''));

            if ('' === $text && !$uploaded && !$selected && !$hasImage) {
                ++$index;

                continue;
            }
            // Keep the submitted row index so uploads still match after an empty row is dropped.
            $filled[$index] = $row;
            ++$index;
        }

        return $filled;
    }

    /**
     * Resolve one image slot: a fresh upload wins, otherwise the chosen library entry, otherwise
     * whatever the row already had.
     *
     * The chosen value is checked against the naming this storage itself mints rather than
     * trusted because it came from a `<select>`: the option list is a convenience, and the
     * stored configuration is the only thing that decides what a section may point at.
     */
    private function image(string $rows, int $index, string $current, FileBag $files, string $field): string
    {
        $upload = $files->get($rows.'_'.$index.'_'.$field.'_file');
        if ($upload instanceof UploadedFile) {
            $error = $upload->getError();
            if (\UPLOAD_ERR_OK === $error && $upload->isValid() && $upload->getSize() > 0) {
                return $this->media->store($upload);
            }
            if (\UPLOAD_ERR_NO_FILE !== $error && \UPLOAD_ERR_OK !== $error) {
                throw new \InvalidArgumentException('Görsel yüklenemedi. Dosya boyutu sunucu sınırını aşabilir.');
            }
        }

        $chosen = trim($current);

        return $this->media->holds($chosen) ? $chosen : '';
    }

    private function hasUpload(string $rows, int $index, FileBag $files, string $field = 'image'): bool
    {
        $upload = $files->get($rows.'_'.$index.'_'.$field.'_file');

        return $upload instanceof UploadedFile
            && \UPLOAD_ERR_OK === $upload->getError()
            && $upload->getSize() > 0;
    }

    /**
     * @return list<string>
     */
    private function slugs(mixed $slugs): array
    {
        $normalized = [];
        foreach (\is_array($slugs) ? $slugs : [] as $slug) {
            if (\is_string($slug) && '' !== trim($slug) && !\in_array($slug, $normalized, true)) {
                $normalized[] = $slug;
            }
        }

        return $normalized;
    }

    private function limit(int $limit): int
    {
        return max(1, min(12, $limit));
    }
}
