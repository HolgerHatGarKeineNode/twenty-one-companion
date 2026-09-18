<?php

namespace App\Portal;

use App\Services\IcsBuilder;
use Carbon\CarbonImmutable;
use Einundzwanzig\Calendar\Calendar;
use Einundzwanzig\Group\Portal\PortalAffordances;
use Einundzwanzig\Group\Portal\PortalEvent;
use Illuminate\Support\Str;
use Livewire\Component;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Share;

/**
 * The app's binding of {@see PortalAffordances} (P4/D9): sharing, links and "add to
 * calendar" run NATIVELY here, not through the browser.
 *
 * The implementations are literally those of this app's own Portal pages
 * (`App\Livewire\PortalPage::openLink`, `InteractsWithCalendar::exportToCalendar`,
 * `Share::url`) — they move here so the package pages can use them without knowing which
 * chassis they run in. The reasons stand at the methods, because they are MEASURED
 * properties of the platform and not taste.
 */
final class NativePortalAffordances implements PortalAffordances
{
    /**
     * Hosts with a native app of their own: open those links in the SYSTEM browser so the
     * intent is handed to the installed app (Telegram/Signal/… then open directly) instead
     * of stranding in an in-app tab.
     *
     * @var list<string>
     */
    private const EXTERNAL_APP_HOSTS = [
        't.me', 'telegram.me', 'telegram.dog',
        'signal.me', 'signal.group',
        'matrix.to',
        'wa.me', 'group.whatsapp.com', 'api.whatsapp.com',
    ];

    public function share(Component $page, string $title, string $text, string $url): void
    {
        Share::url(title: $title, text: $text, url: $url);
    }

    /**
     * Open Portal/web links in the in-app browser (custom tab / SFSafariViewController): the
     * user stays in the app and the back swipe leads back. Only http(s) is opened — the URLs
     * come from Portal data, and other schemes (`nostrsigner:`, `intent:` …) would be intent
     * injection.
     */
    public function openLink(Component $page, string $url): void
    {
        if (! Str::startsWith($url, ['https://', 'http://'])) {
            return;
        }

        $host = Str::chopStart(mb_strtolower((string) parse_url($url, PHP_URL_HOST)), 'www.');

        if (in_array($host, self::EXTERNAL_APP_HOSTS, true)) {
            Browser::open($url);

            return;
        }

        Browser::inApp($url);
    }

    /**
     * "Add to calendar": the native "create event" editor first (Android: ACTION_INSERT),
     * otherwise (iOS/web fallback) an `.ics` file through the share sheet.
     *
     * Unlike on the web ONE date is handed over here and not the Portal's subscription feed:
     * a calendar subscription is a setting in the system calendar, while at this button the
     * user expects the one date he is looking at.
     */
    public function addToCalendar(Component $page, ?int $meetupId, ?PortalEvent $event = null): void
    {
        if ($event === null) {
            return;
        }

        $start = CarbonImmutable::parse($event->start);
        // The Portal API delivers no end → a 2 h default, as in the previous pages.
        $end = $start->addMinutes(120);
        $title = $event->meetupName;

        if (app(Calendar::class)->addEvent($title, $start, $end, $event->location, $event->description)) {
            return;
        }

        $ics = app(IcsBuilder::class)->event(
            title: $title,
            start: $start,
            end: $end,
            location: $event->location,
            description: $event->description,
        );

        $path = storage_path('app/event-'.($event->id ?? $start->getTimestamp()).'.ics');
        file_put_contents($path, $ics);

        Share::file(title: $title, text: __('Termin zum Kalender hinzufügen'), filePath: $path);
    }

    public function canShareNatively(): bool
    {
        return true;
    }
}
