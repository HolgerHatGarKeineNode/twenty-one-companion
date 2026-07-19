<?php

namespace App\Data\Portal;

use App\Data\Portal\Concerns\HasPortalLink;
use App\Data\Portal\Concerns\RendersMarkdown;
use Spatie\LaravelData\Data;

/**
 * Meetup im Karten-Format aus GET /api/meetups (MeetupMapController).
 * intro und logo sind nur bei den Presence-Flags withIntro/withLogos
 * gefüllt, sonst null. top/left/state stammen aus historischen
 * GitHub-Daten für die SVG-Karte der Website; state ist dort je nach
 * Meetup ein String ODER eine Liste von Regionen.
 */
final class MapMeetupData extends Data
{
    use HasPortalLink;
    use RendersMarkdown;

    /**
     * @param  string|list<string>|null  $state
     */
    public function __construct(
        public string $name,
        public string $portalLink,
        public ?string $url,
        public float|int|string|null $top,
        public float|int|string|null $left,
        public string $country,
        public string|array|null $state,
        public string $city,
        public float $longitude,
        public float $latitude,
        public ?string $twitter_username,
        public ?string $website,
        public ?string $simplex,
        public ?string $signal,
        public ?string $nostr,
        public ?NextEventData $next_event,
        public ?string $intro,
        public ?string $logo,
        // Können sich Besucher an-/abmelden bzw. ist die Teilnehmerliste öffentlich?
        // Default true für ältere (gecachte) Map-Antworten ohne diese Felder.
        public bool $rsvp_enabled = true,
        public bool $attendees_public = true,
        // Portal-Meetup-ID (nullable). Quelle für den NIP-29-Raum-`h`
        // (`m`+sha256(id)[:12]). `/api/meetups` liefert das Feld additiv;
        // ältere (gecachte) Antworten ohne id lassen den Raum-Chat-Button aus.
        public ?int $id = null,
        // Existiert für dieses Meetup ein privater NIP-29-Raum auf dem Space?
        // Autoritativ vom Portal geliefert (kennt den gegateten Satz), da der
        // member-only Prod-Relay kind-39000 nur AUTH-gated herausgibt. Default
        // false → alte gecachte Antworten ohne das Feld → kein Raum-Chat-Button.
        public bool $has_room = false,
    ) {}

    public function introHtml(): ?string
    {
        return $this->markdownToHtml($this->intro);
    }

    /** Ländercode (lowercase) für den Regionsfilter. */
    public function countryCode(): string
    {
        return mb_strtolower($this->country);
    }

    /**
     * Externe Links des Meetups als [Label => URL], z. B. für Link-Listen.
     * url ist im Karten-Format telegram_link ?? webpage (siehe Portal-
     * MeetupMapController), daher der t.me-Check und die Website-Dedupe.
     *
     * @return array<string, string>
     */
    public function socialLinks(): array
    {
        $links = [];

        if ($this->url !== null) {
            $label = str_contains($this->url, 't.me/') ? __('Telegram') : __('Community-Link');
            $links[$label] = $this->url;
        }

        if ($this->website !== null && $this->website !== $this->url) {
            $links[__('Website')] = $this->website;
        }

        if ($this->twitter_username !== null) {
            $links[__('X (Twitter)')] = 'https://x.com/'.ltrim($this->twitter_username, '@');
        }

        if ($this->nostr !== null) {
            $links[__('Nostr')] = 'https://njump.me/'.$this->nostr;
        }

        if ($this->signal !== null) {
            $links[__('Signal')] = $this->signal;
        }

        if ($this->simplex !== null) {
            $links[__('SimpleX')] = $this->simplex;
        }

        return $links;
    }
}
