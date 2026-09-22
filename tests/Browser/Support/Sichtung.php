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
     * Every host under this domain is production. The browser tests point each configured
     * origin (PORTAL_URL, NOSTR_SPACE_URL, NOSTR_WORKSPACE_URL, VEREIN_PROXY_BASE) at a dead
     * port, so a request that still reaches one of these hosts comes from client code that
     * ignores its configuration. The ONE place that decides what counts as production —
     * the SichtungTest latch and its control both read it through `isProductionHost()`.
     */
    public const PRODUCTION_DOMAIN = 'einundzwanzig.space';

    /**
     * Production hosts the latch deliberately tolerates, each with its reason. Empty: every
     * production request measured so far was traced to a configurable origin and closed there.
     *
     * @var array<string, string> host => justification
     */
    public const PRODUCTION_HOST_EXEMPTIONS = [];

    public static function isProductionHost(string $host): bool
    {
        $hostname = strtolower((string) preg_replace('/:\d+$/', '', $host));

        if (array_key_exists($hostname, self::PRODUCTION_HOST_EXEMPTIONS)) {
            return false;
        }

        return $hostname === self::PRODUCTION_DOMAIN || str_ends_with($hostname, '.'.self::PRODUCTION_DOMAIN);
    }

    /**
     * @see the class docblock for the reason behind each individual hook.
     */
    private const INIT_SCRIPT = <<<'JS'
        window.__sichtung = { consoleErrors: [], pageErrors: [], netzwerkFehler: [], fremdAnfragen: [] };

        const sichtungCap = (list, entry) => {
            if (list.length < 50) list.push(entry);
        };

        // Every request that leaves the page's own origin, recorded at CALL time — before the
        // network is touched. A status-based record cannot see a request that failed (CORS,
        // refused, aborted) or that succeeded, and both still reached the host. Four doors:
        // fetch, XHR, WebSocket (the relays) and every other resource (img/script/css) via
        // resource timing.
        const sichtungFremd = (url, art) => {
            let parsed;
            try {
                parsed = new URL(String(url instanceof Request ? url.url : url), location.href);
            } catch {
                return;
            }
            if (parsed.origin !== location.origin) {
                sichtungCap(window.__sichtung.fremdAnfragen, { url: parsed.href.slice(0, 300), host: parsed.host, art });
            }
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
        // For element errors the raising element is recorded too: `target.src` alone cannot
        // tell an unreachable image from an `<img src="">` (both report a URL — the empty
        // attribute resolves to the page URL), `getAttribute('src')` can.
        window.addEventListener('error', (e) => {
            const target = e.target && e.target !== window ? e.target : null;
            const entry = {
                art: 'error',
                nachricht: (e.message || (target && (target.src || target.href)) || String(e.error || e) || '').slice(0, 300),
            };
            if (target && target.tagName) {
                entry.element = target.tagName.toLowerCase();
                entry.srcAttribut = target.getAttribute('src');
                entry.html = (target.outerHTML || '').slice(0, 300);
            }
            sichtungCap(window.__sichtung.pageErrors, entry);
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
                sichtungFremd(args[0], 'fetch');
                return origFetch.apply(window, args).then((response) => {
                    if (response.status >= 400) {
                        sichtungCap(window.__sichtung.netzwerkFehler, { url: response.url, status: response.status, art: 'fetch' });
                    }
                    return response;
                });
            };
        }

        const OrigWebSocket = window.WebSocket;
        if (OrigWebSocket) {
            window.WebSocket = new Proxy(OrigWebSocket, {
                construct(target, args, newTarget) {
                    sichtungFremd(args[0], 'websocket');
                    return Reflect.construct(target, args, newTarget);
                },
            });
        }

        if (window.PerformanceObserver) {
            new PerformanceObserver((list) => {
                for (const entry of list.getEntries()) {
                    // fetch/XHR are already recorded at call time above.
                    if (entry.initiatorType !== 'fetch' && entry.initiatorType !== 'xmlhttprequest') {
                        sichtungFremd(entry.name, 'resource:' + entry.initiatorType);
                    }
                }
            }).observe({ type: 'resource', buffered: true });
        }

        const OrigXHR = window.XMLHttpRequest;
        if (OrigXHR) {
            const origOpen = OrigXHR.prototype.open;
            OrigXHR.prototype.open = function (method, url, ...rest) {
                this.__sichtungUrl = url;
                sichtungFremd(url, 'xhr');
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
     * The shared shell chrome as the browser paints it: the computed background of the bottom
     * nav (`<x-group::bottom-nav>`, found by its `data-bottom-nav` grid) and the border
     * radius of its centre search button (`data-palette-open`). Both are `null` on a page
     * that renders no bar (onboarding, the chat room).
     *
     * Why computed values and not class names: the v1.13.0 device sighting found the class
     * strings byte-identical on every page while the bar was white and square on six of
     * them — `layouts::mobile` loaded a stylesheet that had never defined the tokens behind
     * `dark:bg-bg-elevated` and `rounded-fab`, and Tailwind drops such a utility silently.
     */
    private const SHELL_MEASURE = <<<'JS'
        (() => {
            const nav = document.querySelector('[data-bottom-nav]')?.closest('nav') ?? null;
            const fab = document.querySelector('[data-palette-open]');

            return {
                navHintergrund: nav ? getComputedStyle(nav).backgroundColor : null,
                fabRadius: fab ? getComputedStyle(fab).borderTopLeftRadius : null,
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
     * @return array{consoleErrors: list<array{art: string, nachricht: string}>, pageErrors: list<array{art: string, nachricht: string, element?: string, srcAttribut?: string|null, html?: string}>, netzwerkFehler: list<array{url: string, status: int, art: string}>, fremdAnfragen: list<array{url: string, host: string, art: string}>}
     */
    public static function measure(object $webpage): array
    {
        /** @var array{consoleErrors: list<array{art: string, nachricht: string}>, pageErrors: list<array{art: string, nachricht: string, element?: string, srcAttribut?: string|null, html?: string}>, netzwerkFehler: list<array{url: string, status: int, art: string}>, fremdAnfragen: list<array{url: string, host: string, art: string}>} $result */
        $result = $webpage->page()->evaluate('() => window.__sichtung || { consoleErrors: [], pageErrors: [], netzwerkFehler: [], fremdAnfragen: [] }');

        return $result;
    }

    /**
     * @return array{navHintergrund: string|null, fabRadius: string|null}
     */
    public static function measureShell(object $webpage): array
    {
        /** @var array{navHintergrund: string|null, fabRadius: string|null} $result */
        $result = $webpage->script(self::SHELL_MEASURE);

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
