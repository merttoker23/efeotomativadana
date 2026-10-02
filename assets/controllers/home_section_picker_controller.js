import { Controller } from '@hotwired/stimulus';

/**
 * The homepage section editor.
 *
 * Two independent jobs, both of which the server already performs on its own:
 *
 * 1. Live search inside a catalogue picker. The server renders the first page of options and this
 *    replaces them as the administrator types, so a catalogue of tens of thousands of products
 *    is browsable without ever rendering all of it.
 *
 * 2. Composing a list — adding a slide, deleting a tab, moving a product up. Each control is a
 *    real submit button carrying a named action, so the form still works with scripting turned
 *    off; the handlers here only stop the round trip when it can be avoided. Nothing is trusted:
 *    the saved configuration is rebuilt and revalidated server-side from whatever this leaves
 *    in the form.
 */
export default class extends Controller {
    static targets = ['query', 'results', 'selected', 'selectionCount'];
    static values = { kind: String, url: String };

    connect() {
        this.timer = null;
    }

    disconnect() {
        if (this.timer) {
            window.clearTimeout(this.timer);
            this.timer = null;
        }
    }

    // --- catalogue picker -------------------------------------------------------------------

    search() {
        if (!this.hasKindValue || !this.hasUrlValue) {
            return;
        }

        window.clearTimeout(this.timer);
        this.timer = window.setTimeout(() => this.load(), 250);
    }

    async load() {
        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.set('kind', this.kindValue);
        url.searchParams.set('q', this.queryTarget.value);

        let options = [];
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                return;
            }
            options = (await response.json()).options ?? [];
        } catch (error) {
            return;
        }

        const chosen = new Set(this.selectedSlugs());
        this.resultsTarget.replaceChildren(...options.map((option) => {
            const element = document.createElement('option');
            element.value = option.slug;
            element.textContent = option.hint ? `${option.label} — ${option.hint}` : option.label;
            element.selected = chosen.has(option.slug);
            element.disabled = chosen.has(option.slug);
            return element;
        }));

        if (0 === options.length) {
            this.resultsTarget.replaceChildren(this.emptyOption());
        }
    }

    add(event) {
        const chosen = [...this.resultsTarget.selectedOptions].filter((option) => option.value);
        if (0 === chosen.length) {
            return;
        }

        event.preventDefault();
        const existing = new Set(this.selectedSlugs());

        for (const option of chosen) {
            if (existing.has(option.value)) {
                continue;
            }
            existing.add(option.value);
            this.selectedTarget.append(this.selectionItem(option.value, option.textContent));
        }

        this.syncSelection();
    }

    remove(event) {
        event.preventDefault();
        const item = event.target.closest('.cms-selection-item');
        item?.remove();
        this.syncSelection();
    }

    moveUp(event) {
        event.preventDefault();
        const item = event.target.closest('.cms-selection-item');
        const previous = item?.previousElementSibling;
        if (item && previous) {
            this.selectedTarget.insertBefore(item, previous);
            this.syncSelection();
        }
    }

    moveDown(event) {
        event.preventDefault();
        const item = event.target.closest('.cms-selection-item');
        const next = item?.nextElementSibling;
        if (item && next) {
            this.selectedTarget.insertBefore(next, item);
            this.syncSelection();
        }
    }

    selectedSlugs() {
        return [...this.selectedTarget.querySelectorAll('input[type="hidden"]')].map((input) => input.value);
    }

    syncSelection() {
        const items = [...this.selectedTarget.querySelectorAll('.cms-selection-item')];
        const prefix = this.prefix();

        this.selectedTarget.querySelector('.cms-selection-empty')?.remove();

        items.forEach((item, index) => {
            item.querySelectorAll('input[type="hidden"]').forEach((input) => {
                input.name = `${prefix}_${index}`;
            });
            item.querySelectorAll('[name="_pick"]').forEach((button) => {
                button.value = button.value.split(':').map((part, position) => 1 === position ? String(index) : part).join(':');
            });
        });

        if (this.hasSelectionCountTarget) {
            this.selectionCountTarget.value = String(items.length);
        }
    }

    /**
     * The picker's own name prefix, read from the field that counts its own rows.
     *
     * A row added in the browser has every one of its names renumbered, and a name held in a
     * controller value would not be among them. Deriving the prefix from the markup keeps one
     * source of truth for what this control is called.
     */
    prefix() {
        const name = this.selectionCountTarget?.getAttribute('name') ?? 'slugs_count';

        return name.replace(/_count$/, '');
    }

    selectionItem(slug, label) {
        const item = document.createElement('li');
        item.className = 'cms-selection-item';

        const input = document.createElement('input');
        input.type = 'hidden';
        input.value = slug;
        input.name = `${this.prefix()}_0`;

        const text = document.createElement('span');
        text.className = 'cms-selection-label';
        text.textContent = label;

        item.append(input, text);
        for (const [action, glyph, description] of [
            ['moveUp', '↑', 'Yukarı taşı'],
            ['moveDown', '↓', 'Aşağı taşı'],
            ['remove', '×', 'Kaldır'],
        ]) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'cms-selection-action';
            button.textContent = glyph;
            button.dataset.action = `home-section-picker#${action}`;
            button.setAttribute('aria-label', description);
            item.append(button);
        }

        return item;
    }

    emptyOption() {
        const option = document.createElement('option');
        option.textContent = 'Eşleşen kayıt bulunamadı';
        option.disabled = true;
        return option;
    }

    // --- repeatable rows --------------------------------------------------------------------

    addRow() {
        const rows = this.rowTargets;
        if (0 === rows.length || rows.length >= Number(this.countTarget.max) - 1) {
            return;
        }

        const clone = rows[0].cloneNode(true);
        this.prepare(clone, this.rowTargets.length);
        this.rowsTarget.append(clone);
        this.syncRows();
    }

    removeRow(event) {
        const row = event.target.closest('.cms-row');
        if (this.rowTargets.length < 2) {
            return;
        }

        event.preventDefault();
        row?.remove();
        this.syncRows();
    }

    syncRows() {
        this.rowTargets.forEach((row, index) => this.prepare(row, index));
        this.countTarget.value = String(this.rowTargets.length);
    }

    /**
     * Renumber one row so its fields, its picker and its action buttons all agree on its position.
     *
     * The names are flat on purpose — `slides_0_title`, not a nested array — because that is what
     * lets the server read them straight out of the request bag without walking a structure it
     * then has to trust.
     */
    prepare(row, index) {
        const previous = row.dataset.index;
        row.dataset.index = String(index);

        row.querySelectorAll('input, select, textarea, button').forEach((field) => {
            for (const attribute of ['name', 'id', 'data-target']) {
                const value = field.getAttribute(attribute);
                if (value && this.prefixValue && value.includes(`${this.prefixValue}_${previous}_`)) {
                    field.setAttribute(attribute, value.replace(
                        `${this.prefixValue}_${previous}_`,
                        `${this.prefixValue}_${index}_`,
                    ));
                }
            }
            if (field.hasAttribute('data-index')) {
                field.dataset.index = String(index);
            }
        });
    }
}
