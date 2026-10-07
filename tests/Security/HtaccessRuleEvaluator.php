<?php

declare(strict_types=1);

namespace App\Tests\Security;

/**
 * A small evaluator for the subset of Apache directives these two files actually use.
 *
 * It exists because of a specific failure, and the reason it is a class and not three
 * `assertStringContainsString()` calls is worth recording.
 *
 * PHASE 20 shipped a rule that made the whole production site answer 403 Forbidden. The rule
 * denied every request whose filename ended in `.php`, and the Symfony front controller is
 * `public/index.php` — so every page refused the request before PHP ran. The suite was green
 * (1355 tests), the deployment test passed, and a live HTTP smoke test passed. All three ran
 * against Caddy and FrankenPHP, which do not read `.htaccess` at all, and the one test that
 * looked at the file asserted only that the strings `FilesMatch`, `php` and `Require all
 * denied` were *present*. It was a test of spelling. It would have passed just as happily on
 * a rule that denied every request on the server.
 *
 * So the property has to be evaluated, not grepped. The model below is deliberately faithful
 * to the directives in use and nothing more:
 *
 * - `RewriteRule <pattern> <substitution> [F]` — the pattern is a PCRE matched against the
 *   request path *relative to the directory holding the .htaccess*, with the leading slash
 *   stripped. Apache's own convention.
 * - `<FilesMatch "<pattern>">` wrapping `Require all denied` or `Deny from all` — the pattern
 *   is matched against the **basename of the resolved file**, and the section applies to that
 *   directory and every subdirectory of it.
 * - `RewriteCond` is **ignored**, and that is safe here rather than convenient: every `[F]`
 *   rule in these two files is unconditional. The conditional rules are all `[L]` rewrites, and
 *   the evaluator ignores `[L]` because a rewrite to a path is not a denial. The limitation is
 *   documented rather than hidden, because a future conditional `[F]` would need this to grow.
 *
 * What it does not model: per-directory merging of a parent's `<FilesMatch>` into a child
 * context, `DirectoryIndex`, symlinks, aliases, and the rewrite loop. None of them changed the
 * verdict for the rule that broke production, and a simulator that modelled everything would be
 * a second implementation of Apache to keep correct.
 */
final class HtaccessRuleEvaluator
{
    /**
     * The URL prefix the application is mounted under, and the directory the root .htaccess
     * governs. On the live Hostinger deployment the document root is `public_html` and the
     * application is `public_html/yeni`, so `/` is the base and this is that directory.
     */
    public const string BASE_PATH = '/';

    /** The document root of the Symfony front controller, relative to the application root. */
    public const string PUBLIC_DIRECTORY = 'public';

    /** @var list<array{pattern: string, file: string, line: int}> */
    private array $forbiddenRewriteRules = [];

    /** @var list<array{pattern: string, file: string, line: int}> */
    private array $denyingFilesMatchSections = [];

    /**
     * @param array<string, string> $files file path => contents, in the order they apply:
     *                                     the parent first, then the child.
     */
    public function __construct(private readonly array $files)
    {
        foreach ($this->files as $file => $contents) {
            $this->collectForbiddenRewriteRules($file, $contents);
            $this->collectDenyingFilesMatchSections($file, $contents);
        }
    }

    /**
     * Read the two committed .htaccess files from the repository.
     *
     * Committed files, not the container's: the defect this guards was invisible to every test
     * that ran against the running server, because the running server ignores these files. The
     * bytes that ship are the only thing worth asserting on.
     */
    public static function fromProjectRoot(string $root): self
    {
        return new self([
            $root.'/.htaccess' => (string) file_get_contents($root.'/.htaccess'),
            $root.'/public/.htaccess' => (string) file_get_contents($root.'/public/.htaccess'),
        ]);
    }

    /**
     * The rule that denies this request, or null when the request is served.
     *
     * @param string $requestPath the path as the browser asked for it, e.g. `/katalog`
     * @param string $resolvedFile the file the request resolves to, relative to the application
     *                             root, e.g. `public/index.php`
     */
    public function denialFor(string $requestPath, string $resolvedFile): ?string
    {
        $basename = basename($resolvedFile);
        $relative = $this->relativeToBase($requestPath);

        foreach ($this->forbiddenRewriteRules as $rule) {
            if ($this->matches($rule['pattern'], $relative)) {
                return sprintf('%s:%d %s denies %s', $rule['file'], $rule['line'], $rule['pattern'], $requestPath);
            }
        }

        foreach ($this->denyingFilesMatchSections as $section) {
            if ($this->matches($section['pattern'], $basename)) {
                return sprintf('%s:%d FilesMatch "%s" denies %s', $section['file'], $section['line'], $section['pattern'], $requestPath);
            }
        }

        // A parent's <FilesMatch> governs the child's directory too, which is exactly how the
        // root file's `*.php` deny reached public/index.php in production.
        if (str_starts_with($resolvedFile, self::PUBLIC_DIRECTORY.'/')) {
            $publicRelative = substr($resolvedFile, \strlen(self::PUBLIC_DIRECTORY) + 1);

            foreach ($this->forbiddenRewriteRules as $rule) {
                if ($this->matches($rule['pattern'], $publicRelative)) {
                    return sprintf('%s:%d %s denies %s', $rule['file'], $rule['line'], $rule['pattern'], $requestPath);
                }
            }
        }

        return null;
    }

    public function serves(string $requestPath, string $resolvedFile): bool
    {
        return null === $this->denialFor($requestPath, $resolvedFile);
    }

    /** Every deny rule found, for diagnostics in a failure message. */
    public function describeRules(): string
    {
        $lines = [];
        foreach ($this->forbiddenRewriteRules as $rule) {
            $lines[] = sprintf('%s:%d RewriteRule %s [F]', $rule['file'], $rule['line'], $rule['pattern']);
        }
        foreach ($this->denyingFilesMatchSections as $section) {
            $lines[] = sprintf('%s:%d FilesMatch "%s" + deny', $section['file'], $section['line'], $section['pattern']);
        }

        return implode("\n", $lines);
    }

    private function relativeToBase(string $requestPath): string
    {
        if (str_starts_with($requestPath, self::BASE_PATH)) {
            return substr($requestPath, \strlen(self::BASE_PATH));
        }

        return ltrim($requestPath, '/');
    }

    private function collectForbiddenRewriteRules(string $file, string $contents): void
    {
        foreach (preg_split('/\R/', $this->withoutComments($contents)) ?: [] as $index => $line) {
            $line = trim($line);
            if (!preg_match('/^RewriteRule\s+(\S+)\s+(\S+)\s+\[([^\]]*)\]/', $line, $matches)) {
                continue;
            }
            if (!in_array('F', array_map('trim', explode('|', $matches[3])), true)) {
                continue;
            }
            $this->forbiddenRewriteRules[] = ['pattern' => $matches[1], 'file' => $file, 'line' => $index + 1];
        }
    }

    private function collectDenyingFilesMatchSections(string $file, string $contents): void
    {
        $pattern = '/<FilesMatch\s+"((?:[^"\\\\]|\\\\.)*)"\s*>(.*?)<\/FilesMatch>/s';
        if (!preg_match_all($pattern, $this->withoutComments($contents), $matches, \PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches[1] as $index => $patternMatch) {
            $body = $matches[2][$index][0];
            if (!preg_match('/^\s*(Require\s+all\s+denied|Deny\s+from\s+all)\s*$/m', $body)) {
                continue;
            }
            $this->denyingFilesMatchSections[] = [
                // The pattern is used exactly as written. Apache hands the contents of the
                // quoted argument to PCRE unchanged, backslashes included, and PHP's
                // stripcslashes() would quietly turn `^\.` into `^.` — a rule that matches every
                // file on the server instead of every dotfile. The same class of mistake as the
                // rule it was written to catch, one layer further in.
                'pattern' => $patternMatch[0],
                'file' => $file,
                'line' => substr_count(substr($contents, 0, $patternMatch[1]), "\n") + 1,
            ];
        }
    }

    /**
     * Blank out comment lines, keeping every line number intact.
     *
     * Apache ignores a line whose first non-whitespace character is `#`, and an evaluator that
     * does not is worse than no evaluator: both .htaccess files here *describe* the rule that
     * broke production in a comment, quoting the old `FilesMatch` pattern to explain why it was
     * removed. Parsing that as a live directive made the evaluator deny every request on the
     * server — the exact failure it was written to detect, reported against a rule that does not
     * exist. The reported line numbers still refer to the real file because the lines are
     * emptied rather than removed.
     *
     * Only whole-line comments are blanked. A trailing `#` is not treated as a comment, because
     * `#` is legal inside a quoted pattern and guessing wrong there would silently drop a rule.
     */
    private function withoutComments(string $contents): string
    {
        return (string) preg_replace('/^[ \t]*#.*$/m', '', $contents);
    }

    /**
     * Apache patterns are PCRE, so PHP's engine is the right one. An unparseable pattern is a
     * test failure rather than a silent "no match": a pattern this class cannot read is a
     * pattern whose behaviour it would otherwise be inventing. `preg_match()` signals a
     * compilation failure by returning false, not null.
     */
    private function matches(string $pattern, string $subject): bool
    {
        $result = @preg_match('{'.$pattern.'}', $subject);
        if (false === $result) {
            self::fail(sprintf('The .htaccess pattern "%s" is not a valid PCRE: %s.', $pattern, preg_last_error_msg()));
        }

        return 1 === $result;
    }

    private static function fail(string $message): never
    {
        throw new \RuntimeException($message);
    }
}
