<?php

declare(strict_types=1);

/**
 * Behavioral probe for the deeplink-scoping guard in
 * scripts/apply-vendor-patches.sh (pruefe_deeplink_scoping()).
 *
 * A textual check for `config('nativephp.deeplink_paths'` and `deepLinkPathData`
 * anywhere in the target file is satisfied by a fixture where both markers sit in a
 * comment while generateDeepLinkFilters() still hardcodes the whole-host claim — the
 * exact "green light on a defect" shape apply-vendor-patches.sh exists to prevent (see
 * the v1.9.4 notes there). This probe REQUIRES the target file instead and calls its
 * generateDeepLinkFilters() with a known probe host/path, then reads the produced
 * <data> element back: the probe path must be scoped, and the whole-host claim
 * (pathPrefix="/") must not appear.
 *
 * generateDeepLinkFilters() and the deepLinkPathData() it calls touch no $this-> state
 * besides each other (verified against nativephp/mobile 4.4.0, re-verified against 4.5.1
 * on 2026-09-22), so an anonymous class
 * composing the trait is enough here — no Laravel bootstrap needed. Measured at ~60ms
 * against the real vendor file, against ~1-2s for the Laravel-booting config read in
 * scripts/release.sh's pruefe_pfad_prefixe().
 *
 * THE EXIT CODE ALONE IS NOT A VERDICT. `require` runs the target file, and a target
 * that reaches `exit;`/`exit(0);` before the try block terminates this process with
 * status 0 — not catchable, no output, and indistinguishable from success to anyone
 * reading only $?. Measured 2026-09-11 against a one-line `<?php exit(0);` fixture:
 * RC=0, empty stdout, and the calling guard printed "Verhaltensprobe bestanden". So
 * the caller gets a receipt line and must check it.
 *
 * THE RECEIPT DISTINGUISHES "the assertions ran" FROM "the process ended early". That
 * is all it does, and the distinction is worth having: the nonce is baked into the probe
 * PATH and parsed back out of what generateDeepLinkFilters() returned, so a run that
 * never reached the method cannot produce it by accident.
 *
 * IT IS NOT UNFORGEABLE, and three review rounds went into learning why. A target that
 * WANTS to lie read the nonce out of `$argv`, then out of `$_SERVER`, then out of
 * /proc/self/cmdline, and — after all of those were closed — out of `$GLOBALS` with a
 * four-line scan that never mentioned a variable name. Each fix moved the secret to the
 * next channel. The general statement is simply: a secret held in a process you then
 * `require` foreign code into is not a secret, and PHP offers nothing that changes it.
 * Whoever reads this next: do not plug the next channel. The claim is the thing that was
 * wrong, not the channel.
 *
 * SO THE REACH IS: this guard catches ACCIDENT — a downgrade below 4.4.0, an upstream
 * rewrite, markers left behind in dead code, a process that dies before asserting. That
 * is the class that actually shipped as v1.9.4. It does not catch intent, and nothing
 * in-process would. The backstop for intent is scripts/release.sh's
 * pruefe_pfad_prefixe(), which measures the BUILT APK with aapt2 and never reads this
 * file at all.
 *
 * THE NONCE ARRIVES ON STDIN, never as an argument. An argument is readable by the
 * target through $argv, through $_SERVER, and — past every PHP-side scrub — through
 * /proc/self/cmdline. Stdin is consumed and closed before the target is loaded, so
 * there is nothing left to read.
 *
 * Usage: printf '%s' "<nonce>" | php scripts/deeplink-scoping-probe.php <path>
 * On success: exit 0 and `ok <nonce>` as the LAST line of stdout, where the nonce was
 * recovered from the method's output. The caller must check that line, not just the
 * status. Exit 1 and a reason on stderr otherwise — including when the file does not
 * define generateDeepLinkFilters() at all, throws while producing it, or when no nonce
 * arrived.
 */

namespace Native\Mobile\Concerns {
    // Stand-ins so composing RunsAndroid does not require its sibling traits to exist —
    // this probe never calls anything that needs them, and requiring the whole vendor
    // package just to reach one pure method would defeat the point of a cheap check.
    //
    // The list is written out rather than derived from the target's `use …;` line ON
    // PURPOSE: auto-stubbing whatever a future RunsAndroid composes would silently stub
    // a trait that generateDeepLinkFilters() actually needs, which is fail-open. A
    // missing name fails loudly instead ("Trait … not found", exit 1) and a human checks
    // whether the two methods still touch no $this-> state besides each other.
    // Measured 2026-09-22 (nativephp/mobile 4.5.1): RunsAndroid composes
    // DeclaresReleaseAudience on top of the two from 4.4.0; generateDeepLinkFilters()
    // and deepLinkPathData() call only each other and $this->warn() (Command, not a
    // trait — and unreachable on the probe path, it fires only when every configured
    // path was rejected).
    trait DeclaresReleaseAudience {}
    trait PreparesBuild {}
    trait WatchesAndroid {}
}

namespace {
    use Native\Mobile\Concerns\RunsAndroid;

    /**
     * Load the target with the nonce out of its reach.
     *
     * `require` at statement level shares the including scope, so a target file sees
     * every local variable around it. That was a live channel to the nonce until this
     * function existed: a three-line fixture echoing `'ok '.$argv[2]` produced a valid
     * receipt without the method ever running (review finding, 2026-09-11). Loading
     * from inside a function body leaves only $pfad visible; stdin is already drained
     * and closed by the time we get here.
     *
     * This keeps an ACCIDENT from reaching the nonce. It does not keep intent from it —
     * `$GLOBALS` reaches every top-level variable from inside any function, by design.
     * See the reach note in the block above before trying to close that too.
     */
    function laden(string $pfad): void
    {
        require $pfad;
    }

    $ziel = $argv[1] ?? null;

    // Drained and closed before anything foreign is loaded: whatever the target does,
    // the nonce is no longer anywhere it can reach.
    $nonce = is_resource(STDIN) ? trim((string) stream_get_contents(STDIN)) : '';
    fclose(STDIN);

    if ($ziel === null || ! is_file($ziel)) {
        fwrite(STDERR, 'Ziel nicht lesbar: '.($ziel ?? '(kein Argument)')."\n");
        exit(1);
    }

    // No nonce means the caller cannot tell success from an early exit(), so refuse to
    // run rather than hand back a status that cannot be trusted.
    if (! preg_match('/^[0-9a-f]{16,}$/', $nonce)) {
        fwrite(STDERR, "Kein oder ungueltiges Nonce auf stdin — Aufruf: printf '%s' \"<nonce>\" | deeplink-scoping-probe.php <datei>\n");
        exit(1);
    }

    $probeHost = 'deeplink-scoping-probe.test';
    // The nonce rides IN the path, so recovering it from the result proves the method
    // scoped this very run's value — see the receipt note in the block above.
    $probePath = '/deeplink-scoping-probe-'.$nonce.'/';

    try {
        // The target's own output must not land between us and the caller: the last
        // line of stdout is the receipt, and only this file may write it.
        ob_start();
        laden($ziel);
        ob_end_clean();

        if (! trait_exists(RunsAndroid::class, false)) {
            fwrite(STDERR, "Native\\Mobile\\Concerns\\RunsAndroid ist in $ziel kein Trait\n");
            exit(1);
        }

        $sonde = new class
        {
            use RunsAndroid;
        };

        $methode = new ReflectionMethod($sonde, 'generateDeepLinkFilters');
        $methode->setAccessible(true);
        $ergebnis = (string) $methode->invoke($sonde, null, $probeHost, [$probePath]);
    } catch (Throwable $e) {
        fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
        exit(1);
    }

    // Parsed back out rather than compared as a whole string: the receipt below must
    // carry a value this process READ from the method, not one it already had.
    if (! preg_match('~pathPrefix="/deeplink-scoping-probe-([0-9a-f]{16,})/"~', $ergebnis, $treffer)
        || ! hash_equals($nonce, $treffer[1])) {
        fwrite(STDERR, "generateDeepLinkFilters() liefert den konfigurierten Pfad ($probePath) nicht zurueck\n");
        exit(1);
    }

    if (str_contains($ergebnis, 'pathPrefix="/"')) {
        fwrite(STDERR, "generateDeepLinkFilters() beansprucht weiterhin den GANZEN Host (pathPrefix=\"/\")\n");
        exit(1);
    }

    echo 'ok '.$treffer[1]."\n";
}
