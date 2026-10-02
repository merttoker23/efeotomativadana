import { Controller } from '@hotwired/stimulus';

/**
 * The repeatable rows of a homepage section — slides, banners, features, quotes, tabs.
 *
 * "Add row" and "delete row" are submit buttons that ask the server for the same form with one
 * more or one less row, so the editor works with scripting turned off. The prototype row rendered
 * inside a `<template>` is what this controller clones instead, which produces the same result
 * without the round trip. Either way the server receives identical field names and revalidates
 * everything, so nothing decided here is trusted.
 */
const RENAMED = ['name', 'id', 'for', 'data-target', 'value'];

export default class extends Controller {
    static targets = ['rows', 'row', 'count', 'prototype'];
    static values = { prefix: String };

    addRow(event) {
        const prototype = this.prototypeTarget.content.firstElementChild;
        if (!prototype || this.rowTargets.length >= Number(this.countTarget.max)) {
            return;
        }

        event.preventDefault();
        this.rowsTarget.append(prototype.cloneNode(true));
        this.renumber();
    }

    removeRow(event) {
        // A section made of one row cannot be left with none, so its last row has no delete button
        // to press. The guard is here for a click that races the previous one.
        if (this.rowTargets.length < 2) {
            return;
        }

        event.preventDefault();
        event.target.closest('.cms-row')?.remove();
        this.renumber();
    }

    renumber() {
        this.rowTargets.forEach((row, index) => {
            row.dataset.index = String(index);

            row.querySelectorAll(`[${RENAMED.join('], [')}]`).forEach((field) => {
                for (const attribute of RENAMED) {
                    const value = field.getAttribute(attribute);
                    if (value) {
                        field.setAttribute(attribute, this.rename(value, index, field, attribute));
                    }
                }
            });

            const legend = row.querySelector('legend');
            if (legend) {
                legend.textContent = `${index + 1}. satır`;
            }
        });

        this.countTarget.value = String(this.rowTargets.length);
    }

    /**
     * Move one field name from its row to `index`.
     *
     * The names are flat — `tabs_0_slugs_1`, not a nested array — precisely so that a row can be
     * renumbered by rewriting the row number inside a name, which is the only bookkeeping a
     * client-side add or delete needs.
     */
    rename(value, index, field, attribute) {
        if ('value' === attribute && '_pick' === field.getAttribute('name')) {
            const parts = value.split(':');
            if ('remove-row' === parts[0]) {
                parts[1] = String(index);

                return parts.join(':');
            }
            if (3 === parts.length && parts[2].includes('_')) {
                parts[2] = this.rename(parts[2], index, field, 'target');

                return parts.join(':');
            }

            return value;
        }

        return value.replace(
            new RegExp(`(^|[^0-9])${this.prefixValue}_(\\d+)_`, 'g'),
            (match, before) => `${before}${this.prefixValue}_${index}_`,
        );
    }
}
