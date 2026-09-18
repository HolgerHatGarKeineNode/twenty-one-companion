<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * **Is the work language English in the lines this branch ADDED?**
 *
 * The rule ("the work is English, the conversation with the user is German") had to be
 * repeated in three phase briefs of the group repository before it held, and it only held
 * once it was MEASURED — `tests/e2e/support/workLanguage.ts` over there caught 246 German
 * lines in P5 alone. This app had no such measurement; P7 of the navigation revamp adds it,
 * because a rule that is measured in one of three repositories is a rule that drifts in the
 * other two.
 *
 * ── A port, not a second idea ──────────────────────────────────────────────────────
 *
 * Same marker list, same two-marker threshold, same cut-off (`git merge-base` against
 * `master`), same exemption for lines that already existed at that cut-off. It is a port
 * and not a shared file because the two repositories share no runtime: the group's latch is
 * TypeScript under `node --test`, this app's suite is Pest, and adding a `typescript`
 * dependency to this app for one latch would be a bigger change than the latch.
 *
 * **What is different, stated rather than discovered later:** the group's scanner reads
 * `.ts` through the TypeScript parser and is exact there. This one has a line scanner only,
 * for `.php`, `.blade.php`, `.js` and `.env*` — which is every file kind this app's diff
 * contains (measured 2026-09-18: 69 php/blade, 2 js, 0 ts). It masks string literals before
 * it looks for a comment opener, and in a Blade file it accepts PHP comment syntax only
 * inside a PHP region. What it does not understand: heredoc bodies, `?>` inside a string in
 * a Blade file, and a `//` inside a JavaScript regex literal. The first two lose comments
 * (a MISS), the third could raise a false alarm — the corpus case below is the calibration
 * that keeps it honest.
 *
 * ── Measured, not claimed ──────────────────────────────────────────────────────────
 *
 * Comment lines and Pest/JS test names, in ADDED lines only. NOT measured: identifiers,
 * commit messages, branch names, and strings. German product text is DATA — `lang/*.json`,
 * a quoted string in an assertion, a fixture's content. A comment that QUOTES German has
 * the quoted span removed before the language question is asked.
 *
 * ── Fail-closed ────────────────────────────────────────────────────────────────────
 *
 * An unresolvable cut-off throws, a `git` that does not answer throws, and a declared path
 * that matches no tracked file throws. A scanner that is silent when it cannot see is worse
 * than no scanner, because its green is indistinguishable from a clean tree.
 */
final class WorkLanguage
{
    /**
     * Words that are German and are neither English words nor plausible identifiers.
     *
     * Copied verbatim from the group repository's list so the two latches cannot drift
     * apart in what they consider evidence. English homographs (`die`, `also`, `hat`,
     * `war`, `bin`, `an`, `in`, `so`, `um`) are deliberately absent, however German they
     * feel.
     *
     * @var list<string>
     */
    public const GERMAN_MARKERS = [
        'aber', 'auch', 'beim', 'bereits', 'bleibt', 'dann', 'damit', 'dass', 'deshalb',
        'diese', 'dieselbe', 'dieser', 'dieses', 'durch', 'eigene', 'eigenen', 'eine',
        'einem', 'einen', 'einer', 'eines', 'genau', 'gibt', 'hier', 'ihre', 'immer',
        'jede', 'jeder', 'jedes', 'kein', 'keine', 'keinen', 'liegt', 'liest', 'muss',
        'nicht', 'noch', 'nur', 'oder', 'ohne', 'schon', 'seine', 'sich', 'sind', 'sondern',
        'sonst', 'statt', 'steht', 'stehen', 'stellt', 'trotzdem', 'und', 'vom', 'weil',
        'wenn', 'werden', 'wieder', 'wird', 'wurde', 'zeigt', 'zum', 'zur', 'zwei',
    ];

    /** How many distinct markers a line needs before it counts as German. */
    public const MIN_MARKERS = 2;

    /**
     * Remove everything that is DATA rather than prose: code spans and quoted strings.
     *
     * An English comment may quote a German product string — that is the ordinary way to
     * explain why a locator reads the way it does — and it may name German identifiers in
     * backticks. Neither says anything about the language the comment is written in.
     */
    public static function stripQuotedSpans(string $text): string
    {
        $patterns = [
            '/`[^`]*`/u',
            '/„[^""]*["""]/u',
            '/»[^«]*«/u',
            '/"[^"]*"/u',
            "/'[^']*'/u",
            '/"[^"]*"/u',
        ];

        return (string) preg_replace($patterns, ' ', $text);
    }

    /**
     * The distinct German markers of a line, after the data has been taken out.
     *
     * @return list<string>
     */
    public static function germanMarkersIn(string $text): array
    {
        $prose = mb_strtolower(self::stripQuotedSpans($text));
        $hits = [];
        foreach (self::GERMAN_MARKERS as $word) {
            if (preg_match('/(?<![\p{L}\p{N}_])'.$word.'(?![\p{L}\p{N}_])/u', $prose) === 1) {
                $hits[] = $word;
            }
        }
        // ä/ö/ü/ß count as ONE marker, never as a verdict on their own: a single umlaut may
        // well sit in a German name that an English sentence mentions.
        if (preg_match('/[äöüÄÖÜß]/u', self::stripQuotedSpans($text)) === 1) {
            $hits[] = 'umlaut';
        }
        sort($hits);

        return $hits;
    }

    public static function isGerman(string $text): bool
    {
        return count(self::germanMarkersIn($text)) >= self::MIN_MARKERS;
    }

    /** Which reader a file needs, or `null` when this scanner is not responsible for it. */
    public static function readerFor(string $file): ?string
    {
        if (str_ends_with($file, '.blade.php') || str_ends_with($file, '.php')) {
            return 'php';
        }
        if (str_ends_with($file, '.js') || str_ends_with($file, '.mjs') || str_ends_with($file, '.ts')) {
            return 'js';
        }
        if (str_ends_with($file, '.env') || str_contains(basename($file), '.env')) {
            return 'env';
        }

        return null;
    }

    /** Blank out the inside of every string literal, so a `//` in a URL is not an opener. */
    public static function maskStrings(string $line): string
    {
        $out = str_split($line);
        $quote = null;
        for ($i = 0; $i < count($out); $i++) {
            $char = $out[$i];
            if ($quote === null) {
                if ($char === '"' || $char === "'" || $char === '`') {
                    $quote = $char;
                }

                continue;
            }
            if ($char === '\\') {
                $out[$i] = ' ';
                if ($i + 1 < count($out)) {
                    $out[$i + 1] = ' ';
                    $i++;
                }

                continue;
            }
            if ($char === $quote) {
                $quote = null;

                continue;
            }
            $out[$i] = ' ';
        }

        return implode('', $out);
    }

    /** Strip the decoration of a comment line — `/**`, `*`, `//`, `*&#47;`. */
    private static function stripMarkup(string $line): string
    {
        $bare = (string) preg_replace('#^\s*(?:/\*\*?|\*/|\*|//)\s?#', '', $line);

        return trim((string) preg_replace('#\*/\s*$#', '', $bare));
    }

    /**
     * @param  array<int, string>  $lines
     */
    private static function addLine(array &$lines, int $number, string $text): void
    {
        $bare = trim($text);
        if ($bare === '') {
            return;
        }
        $lines[$number] = isset($lines[$number]) ? $lines[$number].' '.$bare : $bare;
    }

    /**
     * Every comment LINE of a PHP, Blade or JavaScript file, keyed by 1-based line number.
     *
     * Three states, and the third is what keeps Blade honest:
     *  · inside `{{-- … --}}` — a Blade comment, valid anywhere in the file;
     *  · inside a C-style block comment;
     *  · **inside a PHP region or not.** A plain `.php` file is PHP from the start, a
     *    `.blade.php` file is markup until `<?php` or `@php`, and a `.js` file is "PHP
     *    region" throughout (same C-style openers, no `#`).
     *
     * @return array<int, string>
     */
    public static function commentLines(string $source, string $file): array
    {
        $isBlade = str_ends_with($file, '.blade.php');
        $isJs = self::readerFor($file) === 'js';
        $lines = [];
        $inBlade = false;
        $inBlock = false;
        $inPhp = ! $isBlade;

        foreach (explode("\n", $source) as $index => $raw) {
            $number = $index + 1;
            $rest = $raw;

            while ($rest !== '') {
                if ($inBlade) {
                    $end = strpos($rest, '--}}');
                    if ($end === false) {
                        self::addLine($lines, $number, $rest);

                        continue 2;
                    }
                    self::addLine($lines, $number, substr($rest, 0, $end));
                    $rest = substr($rest, $end + 4);
                    $inBlade = false;

                    continue;
                }
                if ($inBlock) {
                    $end = strpos($rest, '*/');
                    if ($end === false) {
                        self::addLine($lines, $number, self::stripMarkup($rest));

                        continue 2;
                    }
                    self::addLine($lines, $number, self::stripMarkup(substr($rest, 0, $end)));
                    $rest = substr($rest, $end + 2);
                    $inBlock = false;

                    continue;
                }

                $masked = self::maskStrings($rest);
                /** @var list<array{int, string}> $candidates */
                $candidates = [];
                $push = static function (int|false $at, string $kind) use (&$candidates): void {
                    if ($at !== false) {
                        $candidates[] = [$at, $kind];
                    }
                };
                if (! $isJs) {
                    $push(strpos($masked, '{{--'), 'blade');
                }
                if ($isBlade) {
                    $opens = array_filter([strpos($masked, '<?php'), strpos($masked, '@php')], fn ($at) => $at !== false);
                    $closes = array_filter([strpos($masked, '?>'), strpos($masked, '@endphp')], fn ($at) => $at !== false);
                    if (! $inPhp && $opens !== []) {
                        $push(min($opens), 'php-open');
                    }
                    if ($inPhp && $closes !== []) {
                        $push(min($closes), 'php-close');
                    }
                }
                if ($inPhp) {
                    $push(strpos($masked, '/*'), 'block');
                    $push(strpos($masked, '//'), 'line');
                    if (! $isJs && preg_match('/(?<!\$)#(?!\[)/', $masked, $hit, PREG_OFFSET_CAPTURE) === 1) {
                        $push($hit[0][1], 'line');
                    }
                }
                if ($candidates === []) {
                    continue 2;
                }
                usort($candidates, fn (array $a, array $b) => $a[0] <=> $b[0]);
                [$at, $kind] = $candidates[0];

                if ($kind === 'line') {
                    $text = (string) preg_replace('/^#\s?/', '', substr($rest, $at));
                    self::addLine($lines, $number, self::stripMarkup($text));

                    continue 2;
                }
                if ($kind === 'php-open') {
                    $inPhp = true;
                    $rest = substr($rest, $at + (str_starts_with(substr($masked, $at), '@php') ? 4 : 5));

                    continue;
                }
                if ($kind === 'php-close') {
                    $inPhp = false;
                    $rest = substr($rest, $at + (str_starts_with(substr($masked, $at), '@endphp') ? 7 : 2));

                    continue;
                }
                $inBlade = $kind === 'blade';
                $inBlock = $kind === 'block';
                $rest = substr($rest, $at + ($kind === 'blade' ? 4 : 2));
            }
        }

        return $lines;
    }

    /**
     * Every `#` comment of an env file — the only comment syntax it has.
     *
     * @return array<int, string>
     */
    public static function envCommentLines(string $source): array
    {
        $lines = [];
        foreach (explode("\n", $source) as $index => $raw) {
            $at = strpos($raw, '#');
            if ($at !== false) {
                self::addLine($lines, $index + 1, substr($raw, $at + 1));
            }
        }

        return $lines;
    }

    /**
     * @return array<int, string>
     */
    public static function commentLinesOf(string $source, string $file): array
    {
        return match (self::readerFor($file)) {
            'php', 'js' => self::commentLines($source, $file),
            'env' => self::envCommentLines($source),
            default => [],
        };
    }

    /**
     * Case names: `test('…')`, `it('…')`, `describe('…')`.
     *
     * By pattern and not by parser. The pattern is anchored on a word boundary and on the
     * opening parenthesis, so `latest(…)` and `$this->it(…)` do not match; a name split
     * across lines does not match either, and that is a MISS rather than a false alarm.
     *
     * **A call INSIDE a string literal is not a call.** Without that check the first
     * casualty is this latch's own calibration: a fixture line like
     * `"test('eine deutsche Fallbeschreibung', …)"` is a string, and the scanner reported
     * it as a German case name of its own file (measured 2026-09-18). The keyword is
     * therefore looked up a second time in the string-masked line — if it is blanked there,
     * it stood inside a literal.
     *
     * @return array<int, string>
     */
    public static function testNameLines(string $source, string $file): array
    {
        if (self::readerFor($file) === 'env') {
            return [];
        }
        $names = [];
        foreach (explode("\n", $source) as $index => $raw) {
            if (preg_match('/(?:^|[^\w$>-])(test|it|describe)\s*\(\s*([\'"])((?:[^\'"\\\\]|\\\\.)*)\2/', $raw, $hit, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            [$keyword, $at] = $hit[1];
            if (substr(self::maskStrings($raw), $at, strlen($keyword)) !== $keyword) {
                continue;
            }
            $names[$index + 1] = $hit[3][0];
        }

        return $names;
    }

    /**
     * The added line numbers per file of a `git diff --unified=0` text.
     *
     * Pure, so the parsing can be calibrated against a fixture instead of against whatever
     * the working tree happens to contain. Both spellings of the target path are read: the
     * plain one and the quoted/escaped one git falls back to for a non-ASCII path — this
     * app names its Livewire pages `⚡index.blade.php`, and without the quoted form every
     * hunk from the first such file on would be attributed to the PREVIOUS file.
     *
     * @return array<string, list<int>>
     */
    public static function parseDiff(string $diff): array
    {
        $perFile = [];
        $file = '';
        foreach (explode("\n", $diff) as $line) {
            if (preg_match('#^\+\+\+ (?:"b/(.+)"|b/(.+))$#', $line, $hit) === 1) {
                // Group 1 is the quoted spelling, group 2 the plain one; whichever did not
                // take part is the empty string, never absent — PHP fills a trailing
                // unmatched group only when a LATER one matched, which is exactly this case.
                $file = $hit[1] !== '' ? $hit[1] : $hit[2];

                continue;
            }
            if (str_starts_with($line, '--- ') || str_starts_with($line, 'diff --git ')) {
                continue;
            }
            if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $hit) === 1 && $file !== '' && $file !== 'dev/null') {
                $start = (int) $hit[1];
                $count = isset($hit[2]) ? (int) $hit[2] : 1;
                for ($i = 0; $i < $count; $i++) {
                    $perFile[$file][] = $start + $i;
                }
            }
        }

        return $perFile;
    }

    /**
     * `git`, with path quoting OFF and a failure that is loud.
     *
     * @param  list<string>  $args
     */
    public static function git(string $root, array $args): string
    {
        $process = new Process(['git', '-c', 'core.quotepath=false', '-C', $root, ...$args]);
        $process->setTimeout(120);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('git '.implode(' ', $args).' failed: '.$process->getErrorOutput());
        }

        return $process->getOutput();
    }

    /**
     * The line numbers this working tree ADDED per file, against `$base`.
     *
     * Two sources, because either alone has a hole where the risk is highest: the diff
     * against the merge base (committed AND uncommitted, so the latch answers before the
     * commit), and untracked files counted as added in full — a brand-new file is exactly
     * the case the language drift produced, and `git diff` does not show it.
     *
     * @param  list<string>  $paths
     * @return array<string, list<int>>
     */
    public static function addedLines(string $root, array $paths, string $base): array
    {
        $mergeBase = trim(self::git($root, ['merge-base', $base, 'HEAD']));
        if (preg_match('/^[0-9a-f]{7,40}$/', $mergeBase) !== 1) {
            throw new RuntimeException("no merge base against `{$base}` — the scanner has no cut-off.");
        }
        // A declared path that matches no tracked file is a scanner looking at nothing, and
        // `git diff -- <path>` answers that with silence rather than an error.
        foreach ($paths as $path) {
            if (trim(self::git($root, ['ls-files', '--', $path])) === '') {
                throw new RuntimeException("the declared path `{$path}` matches no tracked file — the scanner is pointed at nothing.");
            }
        }

        $perFile = self::parseDiff(self::git($root, ['diff', '--unified=0', '--no-color', $mergeBase, '--', ...$paths]));

        $untracked = self::git($root, ['ls-files', '--others', '--exclude-standard', '--', ...$paths]);
        foreach (array_filter(array_map('trim', explode("\n", $untracked))) as $path) {
            $absolute = $root.'/'.$path;
            if (! is_file($absolute)) {
                continue;
            }
            $count = count(explode("\n", (string) file_get_contents($absolute)));
            $perFile[$path] = range(1, $count);
        }

        return $perFile;
    }

    /**
     * Did this exact text already exist in this area at `$base`?
     *
     * Moving code does not make its comments new work — and a partial move out of a large
     * file is not a rename, so git's rename detection never fires and the block would stay
     * "added" for good. What this forgives, stated rather than discovered later: a
     * genuinely new German comment that happens to be character-for-character identical to
     * one already in the tree. That is the price, and it is narrow.
     *
     * @param  list<string>  $paths
     */
    public static function existedAtBase(string $root, array $paths, string $base, string $text): bool
    {
        $needle = trim($text);
        if ($needle === '') {
            return true;
        }
        $process = new Process(['git', '-c', 'core.quotepath=false', '-C', $root, 'grep', '--fixed-strings', '--quiet', '-e', $needle, $base, '--', ...$paths]);
        $process->setTimeout(120);
        $process->run();

        // Exit 1 is "no match", which is the answer we want; any other failure would be a
        // scanner that cannot see its own history, and treating that as "existed" would
        // turn the latch off silently. So: only a clean exit means yes.
        return $process->getExitCode() === 0;
    }

    /**
     * Walk the declared paths and report every added German comment line and test name.
     *
     * @param  list<string>  $paths
     * @return array{findings: list<array{file: string, line: int, kind: string, text: string, markers: list<string>}>, examined: int, files: int, addedLineTotal: int, addedFiles: int, moved: int}
     */
    public static function scan(string $root, array $paths, string $base): array
    {
        $perFile = self::addedLines($root, $paths, $base);
        $findings = [];
        $examined = 0;
        $files = 0;
        $addedLineTotal = 0;
        $addedFiles = 0;
        $moved = 0;

        foreach ($perFile as $file => $numbers) {
            $addedLineTotal += count($numbers);
            if (self::readerFor($file) === null) {
                continue;
            }
            $addedFiles++;
            $absolute = $root.'/'.$file;
            if (! is_file($absolute)) {
                continue; // deleted on this branch — nothing to read
            }
            $source = (string) file_get_contents($absolute);
            $added = array_flip($numbers);
            $read = 0;
            foreach ([['comment', self::commentLinesOf($source, $file)], ['test-name', self::testNameLines($source, $file)]] as [$kind, $texts]) {
                foreach ($texts as $line => $text) {
                    if (! isset($added[$line])) {
                        continue;
                    }
                    $read++;
                    if (! self::isGerman($text)) {
                        continue;
                    }
                    if (self::existedAtBase($root, $paths, $base, $text)) {
                        $moved++;

                        continue;
                    }
                    $findings[] = [
                        'file' => $file,
                        'line' => $line,
                        'kind' => $kind,
                        'text' => $text,
                        'markers' => self::germanMarkersIn($text),
                    ];
                }
            }
            $examined += $read;
            if ($read > 0) {
                $files++;
            }
        }

        return [
            'findings' => $findings,
            'examined' => $examined,
            'files' => $files,
            'addedLineTotal' => $addedLineTotal,
            'addedFiles' => $addedFiles,
            'moved' => $moved,
        ];
    }
}
