<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use PHPUnit\Framework\TestCase;

/**
 * The structural half of responsive acceptance, so a regression is caught even where no browser is.
 *
 * This is NOT visual acceptance. It reads the committed stylesheet and the committed templates and
 * asserts three things a reviewer would otherwise have to eyeball at three widths by hand:
 *
 * - every class the new post-purchase templates use is actually defined somewhere in the stylesheet,
 *   because an undefined class is a silently unstyled element rather than an error;
 * - the new layouts collapse at the theme's own breakpoints (1024px and 680px), so they are not
 *   three-column grids that simply overflow on a phone;
 * - no post-purchase rule fixes a pixel width, which is what makes a layout unfixable at any width.
 *
 * The visual half still needs a real browser at >=1301px, ~1024px and <=680px, and that is recorded
 * in docs/PHASE_STATUS.md rather than claimed here.
 */
final class PostPurchaseResponsiveStructureTest extends TestCase
{
    private const array TEMPLATES = [
        'templates/storefront/account/orders.html.twig',
        'templates/storefront/account/order.html.twig',
        'templates/storefront/account/returns.html.twig',
        'templates/storefront/account/return.html.twig',
        'templates/storefront/account/return_form.html.twig',
        'templates/storefront/account/return_blocked.html.twig',
        'templates/storefront/account/_nav.html.twig',
        'templates/storefront/layout/_footer.html.twig',
        'templates/admin/returns/index.html.twig',
        'templates/admin/returns/show.html.twig',
    ];

    private const array RESPONSIVE_CLASSES = [
        'order-list',
        'order-card',
        'order-card-head',
        'order-card-number',
        'order-card-date',
        'order-card-state',
        'order-card-lines',
        'order-card-foot',
        'order-card-total',
        'order-summary-block',
        'order-summary-list',
        'order-timeline',
        'order-timeline-state',
        'return-line-list',
        'return-line',
        'return-line-product',
        'return-line-available',
        'return-line-fields',
    ];

    public function testTheNewTemplatesExist(): void
    {
        foreach (self::TEMPLATES as $template) {
            self::assertFileExists(self::root($template), $template.' is missing.');
        }
    }

    /**
     * Every post-purchase class used in a template has a rule in the stylesheet.
     *
     * A class that exists in markup and not in CSS is not a missing feature, it is an element that
     * silently renders unstyled — which no test and no reviewer reading the diff will notice.
     */
    public function testEveryPostPurchaseClassUsedInATemplateIsDefinedInAStylesheet(): void
    {
        // Both stylesheets, because the admin return screens are styled by admin.css and the
        // storefront ones by storefront.css. Checking only one of them would have "passed" the
        // admin pages by never looking.
        $css = $this->stylesheet().$this->adminStylesheet();
        $missing = [];

        foreach (self::TEMPLATES as $template) {
            $markup = (string) file_get_contents(self::root($template));
            foreach (self::classesIn($markup) as $class) {
                if (in_array($class, self::RESPONSIVE_CLASSES, true)) {
                    continue;
                }
                if (!str_contains($css, '.'.$class)) {
                    $missing[] = sprintf('%s: .%s', basename($template), $class);
                }
            }
        }

        self::assertSame([], $missing, "Classes used in markup but never styled:\n".implode("\n", $missing));
    }

    public function testTheNewStylesheetBlockDefinesEveryPostPurchaseClass(): void
    {
        $css = $this->stylesheet();

        foreach (self::RESPONSIVE_CLASSES as $class) {
            self::assertStringContainsString('.'.$class, $css, sprintf('.%s is never defined.', $class));
        }
    }

    /**
     * The new layouts collapse at the theme's breakpoints.
     *
     * The theme declares 1024px and 680px, and the quality gates check 1024px and <=680px. A
     * two-column return line left as a two-column grid on a 390px phone is the failure this is for.
     */
    public function testTheNewLayoutsCollapseAtTheThemeBreakpoints(): void
    {
        $css = $this->stylesheet();

        self::assertMatchesRegularExpression(
            '/@media\s*\(max-width:\s*1024px\)\s*\{[^}]*\.return-line\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\)/s',
            $css,
            '.return-line must become one column at the tablet breakpoint.',
        );
        self::assertMatchesRegularExpression(
            '/@media\s*\(max-width:\s*680px\)\s*\{.*?\.order-summary-list\s*\{\s*grid-template-columns:\s*minmax\(0,\s*1fr\)/s',
            $css,
            '.order-summary-list must become one column at the phone breakpoint.',
        );
        self::assertMatchesRegularExpression(
            '/@media\s*\(max-width:\s*680px\)\s*\{.*?\.order-timeline li\s*\{\s*grid-template-columns:\s*minmax\(0,\s*1fr\)/s',
            $css,
            '.order-timeline must become one column at the phone breakpoint.',
        );
        self::assertMatchesRegularExpression(
            '/@media\s*\(max-width:\s*680px\)\s*\{.*?\.order-card-foot\s*\{[^}]*flex-direction:\s*column/s',
            $css,
            '.order-card-foot must stack at the phone breakpoint.',
        );
    }

    /**
     * No post-purchase rule pins a pixel width.
     *
     * A fixed `width:` is the one thing that cannot be made responsive afterwards, so a rule that
     * ships with one has to be caught now rather than by PHASE 21's visual pass.
     */
    public function testNoPostPurchaseRulePinsAPixelWidth(): void
    {
        $block = $this->postPurchaseBlock();
        preg_match_all('/\.[a-z0-9-]+\s*\{[^}]*\}/i', $block, $rules);
        self::assertNotEmpty($rules[0], 'The post-purchase stylesheet block was not found.');

        $offenders = [];
        foreach ($rules[0] as $rule) {
            if (preg_match('/(?<![-a-z])width\s*:\s*\d+px/i', $rule, $match, \PREG_OFFSET_CAPTURE)) {
                $offenders[] = trim($match[0][0]);
            }
            if (preg_match('/min-width\s*:\s*(\d+)px/i', $rule, $match) && (int) $match[1] > 400) {
                $offenders[] = trim($match[0]);
            }
        }

        self::assertSame([], $offenders, "Pixel widths that cannot be made responsive:\n".implode("\n", $offenders));
    }

    /** @return list<string> */
    private function classesIn(string $markup): array
    {
        preg_match_all('/class="([^"{}]+)"/', $markup, $matches);
        $classes = [];
        foreach ($matches[1] as $attribute) {
            // Twig expressions and conditional markers are not classes.
            foreach (preg_split('/\s+/', trim($attribute)) ?: [] as $class) {
                $class = trim($class);
                if ('' !== $class && !str_contains($class, '{') && !str_contains($class, '}') && !str_starts_with($class, "'")) {
                    $classes[$class] = true;
                }
            }
        }

        return array_keys($classes);
    }

    private function postPurchaseBlock(): string
    {
        $css = $this->stylesheet();
        $start = strpos($css, '/* Post-purchase account area');
        self::assertNotFalse($start, 'The post-purchase stylesheet block is missing its marker comment.');

        return substr($css, $start);
    }

    private function stylesheet(): string
    {
        $css = self::root('assets/styles/storefront.css');
        self::assertFileExists($css);

        return (string) file_get_contents($css);
    }

    private function adminStylesheet(): string
    {
        $css = self::root('assets/styles/admin.css');
        self::assertFileExists($css);

        return (string) file_get_contents($css);
    }

    private static function root(string $relative): string
    {
        return \dirname(__DIR__, 2).'/'.ltrim($relative, '/');
    }
}
