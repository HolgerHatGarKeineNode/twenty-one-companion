<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

/**
 * The v1.13.0 sighting's own recording instrumentation.
 *
 * `assertNoJavaScriptErrors()`/`assertNoConsoleLogs()` from pest-plugin-browser are NOT
 * enough: its built-in init script (`Pest\Browser\Playwright\InitScript`) only patches
 * `console.log` (not `.error`/`.warn`) and listens on `window.addEventListener('error')`
 * without the capture phase (so it swallows resource errors from `<img>`/`<script>`/
 * `<link>`, which do not bubble) — AND it has no `unhandledrejection` and no network
 * instrumentation at all. This sighting therefore brings its own init script, retrofitted
 * onto an already-open `Context` (`page()->context()->addInitScript()`), followed by a
 * `reload()` — Playwright's `addInitScript` only affects the NEXT document, never
 * retroactively the one already loaded.
 */
final class Sichtung
{
    /**
     * @see the class docblock for the reason behind each individual hook.
     */
    private const INIT_SCRIPT = <<<'JS'
        window.__sichtung = { consoleErrors: [], pageErrors: [], netzwerkFehler: [] };

        const sichtungCap = (list, entry) => {
            if (list.length < 50) list.push(entry);
        };

        const origError = console.error;
        console.error = function (...args) {
            sichtungCap(window.__sichtung.consoleErrors, { art: 'error', nachricht: args.map(String).join(' ').slice(0, 300) });
            origError.apply(console, args);
        };
        const origWarn = console.warn;
        console.warn = function (...args) {
            sichtungCap(window.__sichtung.consoleErrors, { art: 'warn', nachricht: args.map(String).join(' ').slice(0, 300) });
            origWarn.apply(console, args);
        };

        // Capture phase (third argument `true`): the ONLY way window.error also catches
        // resource errors (<img>/<script>/<link> failing to load) — those do not bubble.
        window.addEventListener('error', (e) => {
            const target = e.target && e.target !== window ? e.target : null;
            sichtungCap(window.__sichtung.pageErrors, {
                art: 'error',
                nachricht: (e.message || (target && (target.src || target.href)) || String(e.error || e) || '').slice(0, 300),
            });
        }, true);

        window.addEventListener('unhandledrejection', (e) => {
            const reason = e.reason;
            sichtungCap(window.__sichtung.pageErrors, {
                art: 'unhandledrejection',
                nachricht: String((reason && reason.message) || reason || '').slice(0, 300),
            });
        });

        const origFetch = window.fetch;
        if (origFetch) {
            window.fetch = function (...args) {
                return origFetch.apply(window, args).then((response) => {
                    if (response.status >= 400) {
                        sichtungCap(window.__sichtung.netzwerkFehler, { url: response.url, status: response.status, art: 'fetch' });
                    }
                    return response;
                });
            };
        }

        const OrigXHR = window.XMLHttpRequest;
        if (OrigXHR) {
            const origOpen = OrigXHR.prototype.open;
            OrigXHR.prototype.open = function (method, url, ...rest) {
                this.__sichtungUrl = url;
                this.addEventListener('load', function () {
                    if (this.status >= 400) {
                        sichtungCap(window.__sichtung.netzwerkFehler, { url: this.__sichtungUrl, status: this.status, art: 'xhr' });
                    }
                });
                return origOpen.call(this, method, url, ...rest);
            };
        }
        JS;

    private const OVERFLOW_MEASURE = <<<'JS'
        (() => {
            const doc = document.documentElement;
            const width = window.innerWidth;
            const overflow = doc.scrollWidth > width;
            const overflowing = [];

            if (overflow) {
                for (const el of document.querySelectorAll('body *')) {
                    const r = el.getBoundingClientRect();
                    if (r.width === 0 || r.height === 0) continue;
                    if (r.right > width + 1 || r.left < -1) {
                        const classes = (typeof el.className === 'string' && el.className.trim() !== '')
                            ? '.' + el.className.trim().split(/\s+/).slice(0, 3).join('.')
                            : '';
                        overflowing.push({
                            selector: (el.id ? '#' + el.id : el.tagName.toLowerCase()) + classes,
                            right: Math.round(r.right),
                            left: Math.round(r.left),
                        });
                        if (overflowing.length >= 10) break;
                    }
                }
            }

            const main = document.querySelector('main') || document.body;
            const mainHeight = main ? Math.round(main.getBoundingClientRect().height) : 0;

            return {
                breite: width,
                scrollBreite: doc.scrollWidth,
                ueberlauf: overflow,
                ueberlaufPx: Math.max(0, doc.scrollWidth - width),
                ueberstehend: overflowing.map((o) => ({ selektor: o.selector, rechts: o.right, links: o.left })),
                hauptHoehe: mainHeight,
                leer: mainHeight === 0,
            };
        })()
        JS;

    /**
     * Retrofits the own recording script onto the already-open context and reloads to
     * activate it. Afterwards `window.__sichtung` is present on EVERY further document of
     * this context (including `wire:navigate` transitions).
     */
    public static function instrument(object $webpage): void
    {
        $webpage->page()->context()->addInitScript(self::INIT_SCRIPT);
        $webpage->page()->reload();
        $webpage->page()->waitForLoadState('networkidle');
    }

    /**
     * @return array{consoleErrors: list<array{art: string, nachricht: string}>, pageErrors: list<array{art: string, nachricht: string}>, netzwerkFehler: list<array{url: string, status: int, art: string}>}
     */
    public static function measure(object $webpage): array
    {
        /** @var array{consoleErrors: list<array{art: string, nachricht: string}>, pageErrors: list<array{art: string, nachricht: string}>, netzwerkFehler: list<array{url: string, status: int, art: string}>} $result */
        $result = $webpage->page()->evaluate('() => window.__sichtung || { consoleErrors: [], pageErrors: [], netzwerkFehler: [] }');

        return $result;
    }

    /**
     * @return array{breite: int, scrollBreite: int, ueberlauf: bool, ueberlaufPx: int, ueberstehend: list<array{selektor: string, rechts: int, links: int}>, hauptHoehe: int, leer: bool}
     */
    public static function measureOverflow(object $webpage): array
    {
        /** @var array{breite: int, scrollBreite: int, ueberlauf: bool, ueberlaufPx: int, ueberstehend: list<array{selektor: string, rechts: int, links: int}>, hauptHoehe: int, leer: bool} $result */
        $result = $webpage->script(self::OVERFLOW_MEASURE);

        return $result;
    }
}
