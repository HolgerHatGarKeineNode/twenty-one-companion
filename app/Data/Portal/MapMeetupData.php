<?php

namespace App\Data\Portal;

use App\Data\Portal\Concerns\HasPortalLink;
use App\Data\Portal\Concerns\RendersMarkdown;
use Spatie\LaravelData\Data;

/**
 * Meetup im Karten-Format aus GET /api/meetups (MeetupMapController).
 * intro und logo sind nur bei den Presence-Flags withIntro/withLogos
 * gefüllt, sonst null. top/left/state stammen aus historischen
 * GitHub-Daten für die SVG-Karte der Website.
 */
final class MapMeetupData extends Data
{
    use HasPortalLink;
    use RendersMarkdown;

    public function __construct(
        public string $name,
        public string $portalLink,
        public ?string $url,
        public float|int|string|null $top,
        public float|int|string|null $left,
        public string $country,
        public ?string $state,
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
    ) {}

    public function introHtml(): ?string
    {
        return $this->markdownToHtml($this->intro);
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
