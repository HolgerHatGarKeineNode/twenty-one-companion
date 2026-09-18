<?php

declare(strict_types=1);

use Tests\Support\WorkLanguage;

/**
 * **The work language of this app's own branch work — measured, not asked for.**
 *
 * The rule is the house doctrine: the work is English (code comments, identifiers, commit
 * messages, test names), the conversation with the user is German. It held in the group
 * repository only once a latch measured it — `tests/e2e/support/workLanguage.ts` there
 * caught 246 German lines in one phase. This app had no equivalent, which is why P7 of the
 * navigation revamp adds one.
 *
 * The scanner lives in `Tests\Support\WorkLanguage`; its docblock states what it reads and
 * where it is imprecise. This file is the calibration plus the measurement — in that order,
 * because a latch that has only ever been seen green is indistinguishable from one that
 * cannot fire at all.
 *
 * ── The corpus half ────────────────────────────────────────────────────────────────
 *
 * Two corpora, taken from lines that really stand in this repository: English comment lines
 * must not carry two markers (false-alarm rate), German blocks must be caught (sensitivity).
 * Both are asserted as whole blocks and not line by line, because a block is the unit a
 * human writes.
 */
$root = dirname(__DIR__, 2);

/**
 * The cut-off. `master` and not a date: a branch is measured against what it branched off,
 * and `git merge-base` turns that into a commit even after `master` has moved on.
 */
const WL_BASE = 'master';

/**
 * The paths this latch is responsible for.
 *
 * `app`, `config`, `resources` and `tests` are where this app's own work lives; the
 * `.env.example` is in because the group's latch found German there once. `vendor`,
 * `storage` and `packages/push`'s Kotlin are out — the first two are not this app's work,
 * the third is a file kind the scanner does not read and would count as "examined nothing".
 */
const WL_PATHS = ['app', 'config', 'resources', 'tests', '.env.example'];

// ══ 1. Can the detector see anything at all? ══════════════════════════════════════

test('CALIBRATION: the detector separates a German line from an English one', function (): void {
    expect(WorkLanguage::isGerman('Der Zähler steht auf null, weil die Antwort noch fehlt.'))->toBeTrue()
        ->and(WorkLanguage::isGerman('The counter stays at zero because the answer has not arrived.'))->toBeFalse();
});

test('CALIBRATION: two markers are needed, one is not enough', function (): void {
    // A single German word in an English sentence (a quoted label, a product term) must not
    // be a verdict — that threshold is the whole reason the marker list is usable.
    expect(WorkLanguage::germanMarkersIn('The nur flag is set here'))->toBe(['nur'])
        ->and(WorkLanguage::isGerman('The nur flag is set here'))->toBeFalse();
});

test('CALIBRATION: quoted product text and code spans do not make a line German', function (): void {
    $line = 'The button says „Alles als gelesen markieren" and `raumZeile` finds it';
    expect(WorkLanguage::isGerman($line))->toBeFalse();
});

test('CALIBRATION: PHP comments are read — and only comments', function (): void {
    $source = implode("\n", [
        '<?php',
        '// eine deutsche Zeile, die nicht durchgeht',
        '$url = "https://example.test/pfad"; // und hier noch eine',
        '# eine dritte, mit Doppelkreuz',
        '#[Attribute]',
        '$hash = "#nicht-ein-kommentar";',
        '/* eine vierte, im Block */',
    ]);
    $lines = WorkLanguage::commentLines($source, 'probe.php');

    expect(array_keys($lines))->toBe([2, 3, 4, 7]);
});

test('CALIBRATION: Blade comments — `{{-- --}}`, and PHP syntax only inside a PHP region', function (): void {
    $source = implode("\n", [
        '{{-- eine deutsche Zeile, die nicht durchgeht --}}',
        '<a href="https://example.test/pfad">nicht ein Kommentar</a>',
        '@php',
        '// hier schon, denn hier ist PHP',
        '@endphp',
        '<x-foo class="a//b" />',
    ]);
    $lines = WorkLanguage::commentLines($source, 'probe.blade.php');

    expect(array_keys($lines))->toBe([1, 4]);
});

test('CALIBRATION: JavaScript comments are read', function (): void {
    $source = implode("\n", [
        "const url = 'https://example.test/pfad'",
        '// eine deutsche Zeile, die nicht durchgeht',
        '/* und eine zweite */',
    ]);
    expect(array_keys(WorkLanguage::commentLines($source, 'probe.js')))->toBe([2, 3]);
});

test('CALIBRATION: Pest case names are read, and only from test calls', function (): void {
    $source = implode("\n", [
        "test('eine deutsche Fallbeschreibung, die nicht durchgeht', function () {});",
        "it('an English case', function () {});",
        "\$latest = \$query->latest('created_at');",
    ]);
    $names = WorkLanguage::testNameLines($source, 'probe.php');

    expect(array_keys($names))->toBe([1, 2]);
});

test('CALIBRATION: the diff parser survives a quoted, non-ASCII path', function (): void {
    $diff = implode("\n", [
        'diff --git "a/resources/views/pages/meetups/\342\232\241index.blade.php" "b/resources/views/pages/meetups/\342\232\241index.blade.php"',
        '--- "a/resources/views/pages/meetups/\342\232\241index.blade.php"',
        '+++ "b/resources/views/pages/meetups/\342\232\241index.blade.php"',
        '@@ -10,0 +11,2 @@',
        '+ eins',
        '+ zwei',
    ]);
    $parsed = WorkLanguage::parseDiff($diff);

    expect(array_keys($parsed))->toHaveCount(1)
        ->and(array_values($parsed)[0])->toBe([11, 12]);
});

// ══ 2. The two corpora — false alarms and sensitivity ═════════════════════════════

test('MEASURED: not one English comment line of the corpus carries two markers', function (): void {
    $corpus = [
        'The point of the case: P4 built the author route and NOTHING linked there.',
        'Wait for the GRID. The card list only stands after the relay answered.',
        'One request per relay, EOSE per subscription id, a logged drop counter.',
        'A guard that has only ever been seen green is indistinguishable from one that cannot fire.',
        'The bottom nav is fixed markup with three slots, not a config list.',
        'Signing out lives on the „Ich" page since P3 — until then it sat behind the profile chip.',
    ];
    $flagged = array_values(array_filter($corpus, fn (string $line) => WorkLanguage::isGerman($line)));

    expect($flagged)->toBe([]);
});

test('MEASURED: the detector catches German comment BLOCKS, which is the unit that matters', function (): void {
    $blocks = [
        ['Der Zähler steht auf null, weil die Antwort noch fehlt.', 'Ohne sie bleibt die Zeile leer.'],
        ['Zwei Quellen, eine Antwort: der Raum liegt entweder im Workspace', 'oder im eigenen Set des Lesers.'],
        ['Hier wird nur gemessen, was die Seite wirklich zeigt.'],
    ];
    foreach ($blocks as $block) {
        $hit = array_values(array_filter($block, fn (string $line) => WorkLanguage::isGerman($line)));
        expect($hit)->not->toBe([]);
    }
});

// ══ 3. Fail-closed ═══════════════════════════════════════════════════════════════

test('FAIL-CLOSED: an unresolvable cut-off throws instead of reporting a clean tree', function () use ($root): void {
    expect(fn () => WorkLanguage::addedLines($root, WL_PATHS, 'kein-solcher-zweig-4711'))
        ->toThrow(RuntimeException::class);
});

test('FAIL-CLOSED: a declared path that matches no tracked file throws', function () use ($root): void {
    expect(fn () => WorkLanguage::addedLines($root, ['app', 'gibt-es-nicht'], WL_BASE))
        ->toThrow(RuntimeException::class);
});

// ══ 4. The measurement ═══════════════════════════════════════════════════════════

test('the lines this branch ADDED are English — comments and test names', function () use ($root): void {
    $report = WorkLanguage::scan($root, WL_PATHS, WL_BASE);

    // The floor is an IMPLICATION and not a bare `examined > 0`: on `master` an empty diff
    // is the correct answer, while "this branch added lines to files of a kind I read,
    // therefore I read comment lines in them" is right in both worlds — and it is what
    // falls when the reader stops matching the files the branch actually touched.
    if ($report['addedFiles'] > 0) {
        expect($report['examined'])->toBeGreaterThan(0);
    }

    $lines = array_map(
        fn (array $f) => sprintf('  %s:%d [%s] {%s}%s      %s', $f['file'], $f['line'], $f['kind'], implode(', ', $f['markers']), PHP_EOL, $f['text']),
        $report['findings'],
    );

    expect($lines)->toBe([], implode(PHP_EOL, [
        sprintf(
            'German in added lines: %d finding(s). Read %d comment lines / test names in %d file(s); %d added line(s) in %d file(s) of a readable kind; %d line(s) exempt as pre-existing.',
            count($report['findings']),
            $report['examined'],
            $report['files'],
            $report['addedLineTotal'],
            $report['addedFiles'],
            $report['moved'],
        ),
        ...$lines,
    ]));
});
