<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use Illuminate\Support\Facades\Route;
use swentel\nostr\Event\Event;
use swentel\nostr\Nip19\Nip19Helper;

/**
 * The single source of truth for "every GET route the app answers, and what to do
 * with it in the pre-release sighting". Two lists: `pages()` (viewable pages — visited,
 * measured, screenshotted) and `exceptions()` (assets/API endpoints/deep-link callbacks —
 * named individually with a reason, never swept away by a pattern).
 *
 * `check()` is the fail-closed guard: it diffs this inventory against whatever
 * `route:list` says RIGHT NOW, so a route added after this file was written shows up as
 * `orphanedInSurvey` instead of silently going unseen — exactly the failure mode that
 * produced this survey in the first place (nine routes in the old SmokeTest no longer
 * existed as pages at all).
 */
final class RouteInventory
{
    /** Fixture pubkey — 64 hex characters, not a real key. */
    public const PUBKEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** Fixture event id — 64 hex characters, the basis for naddr/nevent AND the raw forge id. */
    public const EVENT_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public const ROOM_H = 'sighting-room';

    public const COURSE_ID = 5;

    public const LECTURER_ID = 3;

    public const MEETUP_SLUG = 'aschaffenburg';

    /**
     * The nine legacy routes from the original `SmokeTest.php` — named here explicitly
     * because the DoD asks for exactly this one, individually checked 301 assertion.
     *
     * @var list<string>
     */
    public const NINE_LEGACY_ROUTES = [
        '/meetups',
        '/events',
        '/map',
        '/courses',
        '/profile',
        '/mine',
        '/mine/places',
        '/mine/teaching',
        '/more',
    ];

    /**
     * @return array<string, string> bech32 identifiers, computed once (expensive enough not
     *                               to rebuild on every call)
     */
    public static function identifiers(): array
    {
        static $identifiers = null;

        if ($identifiers !== null) {
            return $identifiers;
        }

        $nip19 = new Nip19Helper;

        $article = (new Event)->setId(self::EVENT_ID)->setPublicKey(self::PUBKEY)->setKind(30023);
        $repo = (new Event)->setId(self::EVENT_ID)->setPublicKey(self::PUBKEY)->setKind(30617);
        $message = (new Event)->setId(self::EVENT_ID)->setPublicKey(self::PUBKEY)->setKind(1);

        return $identifiers = [
            'naddrArticle' => $nip19->encodeAddr($article, 'sighting-article', 30023, self::PUBKEY),
            'naddrForge' => $nip19->encodeAddr($repo, 'sighting-repo', 30617, self::PUBKEY),
            'nevent' => $nip19->encodeEvent($message, [], self::PUBKEY, 1),
            'npub' => $nip19->encodeNpub(self::PUBKEY),
        ];
    }

    /**
     * Every visible page: URI pattern (as `Route::uri()` returns it) => visit data.
     *
     * @return array<string, array{path: string, gated: bool}>
     */
    public static function pages(): array
    {
        $k = self::identifiers();

        return [
            '/' => ['path' => '/', 'gated' => false],
            'articles' => ['path' => '/articles', 'gated' => false],
            'articles/autor/{autor}' => ['path' => '/articles/autor/'.$k['npub'], 'gated' => false],
            'articles/{naddr}' => ['path' => '/articles/'.$k['naddrArticle'], 'gated' => false],
            'bereich/artikel' => ['path' => '/bereich/artikel', 'gated' => false],
            'bereich/chat' => ['path' => '/bereich/chat', 'gated' => true],
            'bereich/forge' => ['path' => '/bereich/forge', 'gated' => true],
            'bereich/kurse' => ['path' => '/bereich/kurse', 'gated' => false],
            'bereich/kurse/{id}' => ['path' => '/bereich/kurse/'.self::COURSE_ID, 'gated' => false],
            'bereich/kurse/referenten/{id}' => ['path' => '/bereich/kurse/referenten/'.self::LECTURER_ID, 'gated' => false],
            'bereich/leute' => ['path' => '/bereich/leute', 'gated' => true],
            'bereich/meetups' => ['path' => '/bereich/meetups', 'gated' => false],
            'bereich/meetups/{slug}' => ['path' => '/bereich/meetups/'.self::MEETUP_SLUG, 'gated' => false],
            'bereich/wallet' => ['path' => '/bereich/wallet', 'gated' => true],
            'bookmarks' => ['path' => '/bookmarks', 'gated' => false],
            'courses' => ['path' => '/courses', 'gated' => false],
            'courses/{id}' => ['path' => '/courses/'.self::COURSE_ID, 'gated' => false],
            'directory' => ['path' => '/directory', 'gated' => false],
            'events' => ['path' => '/events', 'gated' => false],
            'forge' => ['path' => '/forge', 'gated' => false],
            'forge/{naddr}' => ['path' => '/forge/'.$k['naddrForge'], 'gated' => true],
            'forge/{naddr}/issues/{id}' => ['path' => '/forge/'.$k['naddrForge'].'/issues/'.self::EVENT_ID, 'gated' => true],
            'forge/{naddr}/pulls/{id}' => ['path' => '/forge/'.$k['naddrForge'].'/pulls/'.self::EVENT_ID, 'gated' => true],
            'ich' => ['path' => '/ich', 'gated' => false],
            'ich/einstellungen' => ['path' => '/ich/einstellungen', 'gated' => false],
            'ich/inhalte' => ['path' => '/ich/inhalte', 'gated' => false],
            'ich/inhalte/lehre' => ['path' => '/ich/inhalte/lehre', 'gated' => false],
            'ich/inhalte/meetups' => ['path' => '/ich/inhalte/meetups', 'gated' => false],
            'ich/inhalte/orte' => ['path' => '/ich/inhalte/orte', 'gated' => false],
            'ich/inhalte/termine' => ['path' => '/ich/inhalte/termine', 'gated' => false],
            'ich/lesezeichen' => ['path' => '/ich/lesezeichen', 'gated' => true],
            'ich/verein' => ['path' => '/ich/verein', 'gated' => true],
            'join' => ['path' => '/join', 'gated' => true],
            'lecturers/{id}' => ['path' => '/lecturers/'.self::LECTURER_ID, 'gated' => false],
            'map' => ['path' => '/map', 'gated' => false],
            'meetups' => ['path' => '/meetups', 'gated' => false],
            'meetups/{slug}' => ['path' => '/meetups/'.self::MEETUP_SLUG, 'gated' => false],
            'messages' => ['path' => '/messages', 'gated' => false],
            'mine' => ['path' => '/mine', 'gated' => false],
            'mine/places' => ['path' => '/mine/places', 'gated' => false],
            'mine/teaching' => ['path' => '/mine/teaching', 'gated' => false],
            'more' => ['path' => '/more', 'gated' => false],
            'nostr-login' => ['path' => '/nostr-login', 'gated' => false],
            'onboarding' => ['path' => '/onboarding', 'gated' => false],
            'postfach' => ['path' => '/postfach', 'gated' => true],
            'profile' => ['path' => '/profile', 'gated' => false],
            'rooms/{h}' => ['path' => '/rooms/'.self::ROOM_H, 'gated' => true],
            'rooms/{h}/thread/{nevent}' => ['path' => '/rooms/'.self::ROOM_H.'/thread/'.$k['nevent'], 'gated' => true],
            'settings' => ['path' => '/settings', 'gated' => false],
            'settings/space' => ['path' => '/settings/space', 'gated' => false],
            'settings/wallet' => ['path' => '/settings/wallet', 'gated' => false],
            'spaces' => ['path' => '/spaces', 'gated' => false],
            'start' => ['path' => '/start', 'gated' => false],
            'suche/portal-index' => ['path' => '/suche/portal-index', 'gated' => false],
            'updates' => ['path' => '/updates', 'gated' => false],
            'verein/beitritt' => ['path' => '/verein/beitritt', 'gated' => true],
        ];
    }

    /**
     * Every route that is NOT a view — asset, API endpoint or deep-link callback. Named and
     * justified individually (no collective patterns).
     *
     * @return array<string, string>
     */
    public static function exceptions(): array
    {
        return [
            'app/auth' => 'Deep-link receiver of the portal login handoff (einundzwanzig://auth), expects an external token query parameter — not a page a human navigates to.',
            'auth' => 'Same deep-link callback role as app/auth, just via the verified App Link path instead of the custom scheme.',
            'flux/editor.css' => 'Flux UI asset (CSS of the editor component), no page content.',
            'flux/editor.js' => 'Flux UI asset (JS of the editor component).',
            'flux/editor.min.js' => 'Flux UI asset (minified JS of the editor component).',
            'flux/flags/{country}' => 'Flux UI asset (flag SVG per country), no page content.',
            'flux/flux.js' => 'Flux UI asset (base JS of Flux).',
            'flux/flux.min.js' => 'Flux UI asset (minified base JS of Flux).',
            'flux/phone.js' => 'Flux UI asset (phone field JS).',
            'flux/phone.min.js' => 'Flux UI asset (minified phone field JS).',
            'flux/phone-utils.js' => 'Flux UI asset (phone field helper functions).',
            'livewire-23a0808b/css/{component}.css' => 'Livewire frontend asset (component CSS), no page content.',
            'livewire-23a0808b/css/{component}.global.css' => 'Livewire frontend asset (global component CSS).',
            'livewire-23a0808b/js/{component}.js' => 'Livewire frontend asset (component JS).',
            'livewire-23a0808b/livewire.csp.min.js.map' => 'Livewire frontend asset (source map).',
            'livewire-23a0808b/livewire.min.js' => 'Livewire frontend asset (core JS).',
            'livewire-23a0808b/livewire.min.js.map' => 'Livewire frontend asset (source map of the core JS).',
            'livewire-23a0808b/preview-file/{filename}' => 'Livewire file preview, reachable only via a signed upload token — not a navigable page.',
            'nostr/challenge' => 'JSON API endpoint (returns a login nonce), no HTML.',
            'portal/nostr-challenge' => 'JSON API endpoint (portal handoff challenge), no HTML.',
            'signed/{payload}' => 'NIP-55 signer deep-link callback (einundzwanzig://signed/…), not a path a human types.',
            'storage/{path}' => 'Storage symlink asset (images/files), no page content.',
            'up' => "The framework's health-check endpoint.",
            'verein/zurueck' => 'Fixed return path from the BTCPay checkout, redirects immediately to /verein/beitritt — not a page of its own, its target is already in the survey list.',
        ];
    }

    /**
     * Every URI pattern the app answers for GET RIGHT NOW — the living truth the survey
     * list is checked against.
     *
     * @return list<string>
     */
    public static function liveGetUriPatterns(): array
    {
        $patterns = [];

        foreach (Route::getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true)) {
                $patterns[] = $route->uri();
            }
        }

        sort($patterns);

        return array_values(array_unique($patterns));
    }

    /**
     * The fail-closed guard: compares the given list of live URI patterns against
     * `pages()` + `exceptions()`. Takes both collections as parameters (instead of reading
     * them itself) so a mutation probe can remove one route and observe the diagnosis
     * change without touching the real inventory.
     *
     * @param  list<string>  $liveUriPatterns
     * @param  array<string, array{path: string, gated: bool}>|null  $pages
     * @param  array<string, string>|null  $exceptions
     * @return array{missingFromSurvey: list<string>, orphanedInSurvey: list<string>}
     */
    public static function check(array $liveUriPatterns, ?array $pages = null, ?array $exceptions = null): array
    {
        $pages ??= self::pages();
        $exceptions ??= self::exceptions();

        $known = array_merge(array_keys($pages), array_keys($exceptions));

        $missingFromSurvey = array_values(array_diff($liveUriPatterns, $known));
        $orphanedInSurvey = array_values(array_diff($known, $liveUriPatterns));

        sort($missingFromSurvey);
        sort($orphanedInSurvey);

        return [
            'missingFromSurvey' => $missingFromSurvey,
            'orphanedInSurvey' => $orphanedInSurvey,
        ];
    }
}
