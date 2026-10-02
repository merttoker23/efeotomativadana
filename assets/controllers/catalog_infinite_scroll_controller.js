import { Controller } from '@hotwired/stimulus';

/**
 * The category listing, read as one long grid.
 *
 * Thirty products arrive with the page and the next thirty of the very same query are asked for as
 * the customer reaches the bottom of the grid, then appended below the cards already on screen.
 * Nothing scrolls sideways, nothing is re-rendered and nothing navigates: the grid the page came
 * with is the grid the products are added to, so the forms inside the new cards work exactly as
 * the ones above them do.
 *
 * The address of the next page is read from each fetched page rather than counted here, which is
 * what makes the page itself decide what comes next — including deciding that nothing does, and
 * that is what stops the observer on the last page. Every filter, the sort and the category stay
 * in that address because the address is built from the request that produced the page.
 *
 * Changing a filter or the sort is a link or a form the browser follows, so the listing starts again
 * from the first thirty products. A browser that cannot run this file gets the paged listing
 * instead, which the template renders inside `noscript` for exactly that reason.
 */
export default class extends Controller {
    static targets = ['grid', 'range', 'sentinel', 'status'];

    static values = {
        nextUrl: String,
    };

    connect() {
        this.observer = null;
        this.loading = false;
        this.shown = new Set(this.renderedProducts());

        if (this.canLoadMore()) {
            this.observe();
        }
    }

    disconnect() {
        this.stopObserving();
    }

    /**
     * Ask for the next page and add what it carries.
     *
     * Only ever one page in flight: the observer keeps reporting an intersecting sentinel while the
     * request is on its way, and a second request sent from there would append the same products
     * twice. It is paused for the duration and resumed afterwards.
     */
    async load() {
        if (this.loading || !this.canLoadMore()) {
            return;
        }

        this.loading = true;
        this.stopObserving();
        this.announce('Daha fazla ürün yükleniyor.');

        try {
            const response = await fetch(this.nextUrlValue, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) {
                this.stop('Ürünler yüklenemedi. Sayfayı yenileyerek tekrar deneyebilirsiniz.');

                return;
            }
            this.append(new DOMParser().parseFromString(await response.text(), 'text/html'));
        } catch {
            this.stop('Ürünler yüklenemedi. Sayfayı yenileyerek tekrar deneyebilirsiniz.');
        } finally {
            this.loading = false;
            if (this.canLoadMore()) {
                this.observe();
            }
        }
    }

    /**
     * Move the fetched page's products into the grid on screen.
     *
     * A product already rendered is skipped rather than moved: the repository clamps a page asked
     * for past the end to the last one, and the grid must not fill with the same card twice because
     * of it. A page that adds nothing new is the end of the listing whichever number was asked for,
     * so the observer is not resumed.
     */
    append(page) {
        let added = 0;
        page.querySelectorAll('.catalog-results .product-grid > *').forEach((card) => {
            const product = card.dataset.product ?? '';
            if ('' !== product && this.shown.has(product)) {
                return;
            }
            if ('' !== product) {
                this.shown.add(product);
            }

            this.gridTarget.append(document.importNode(card, true));
            added += 1;
        });

        // What comes next is whatever the fetched page says it is, and the range it reports is the
        // range it actually rendered — nothing here counts on its own and could drift from it.
        this.nextUrlValue = page
            .querySelector('.catalog-listing')
            ?.getAttribute('data-catalog-infinite-scroll-next-url-value') ?? '';
        const range = page.querySelector('.catalog-summary-range')?.textContent?.trim();
        if (this.hasRangeTarget && range) {
            this.rangeTarget.textContent = range;
        }

        if (0 === added) {
            this.nextUrlValue = '';
        }

        this.announce(added > 0 ? `${added} ürün daha eklendi.` : 'Listenin sonu.');
    }

    /**
     * Stop asking for pages that do not arrive.
     *
     * The sentinel is still on screen after a failure, so an observer that resumed would ask again
     * straight away and keep asking. The listing stops here instead and says so, which leaves the
     * reload as the one thing left to try.
     */
    stop(message) {
        this.nextUrlValue = '';
        this.announce(message);
    }

    /** The products the page itself rendered, so the first fetch has something to compare against. */
    renderedProducts() {
        if (!this.hasGridTarget) {
            return [];
        }

        return [...this.gridTarget.children].map((card) => card.dataset.product ?? '');
    }

    canLoadMore() {
        return '' !== this.nextUrlValue && this.hasSentinelTarget && this.hasGridTarget;
    }

    observe() {
        if (this.observer) {
            return;
        }

        this.observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    this.load();
                }
            },
            // Asked for before the bottom of the grid is reached, so the next page is usually
            // already there by the time the customer gets to the end of the current one.
            { rootMargin: '480px 0px' },
        );
        this.observer.observe(this.sentinelTarget);
    }

    stopObserving() {
        if (!this.observer) {
            return;
        }

        this.observer.disconnect();
        this.observer = null;
    }

    announce(message) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = message;
        }
    }
}