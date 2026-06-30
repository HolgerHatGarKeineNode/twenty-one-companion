<?php

use App\Services\IcsBuilder;
use Carbon\CarbonImmutable;

function ics(): IcsBuilder
{
    return new IcsBuilder;
}

it('builds a single-event vcalendar', function () {
    $out = ics()->event(
        title: 'Bitcoin Meetup',
        start: CarbonImmutable::parse('2026-07-15 19:00', 'Europe/Berlin'),
    );

    expect($out)
        ->toContain('BEGIN:VCALENDAR')
        ->toContain('VERSION:2.0')
        ->toContain('BEGIN:VEVENT')
        ->toContain('SUMMARY:Bitcoin Meetup')
        ->toContain('END:VEVENT')
        ->toContain('END:VCALENDAR');
});

it('uses CRLF line endings as RFC 5545 requires', function () {
    $out = ics()->event(title: 'X', start: CarbonImmutable::parse('2026-07-15 19:00', 'UTC'));

    expect($out)->toContain("\r\n")
        ->and(str_contains(str_replace("\r\n", '', $out), "\n"))->toBeFalse();
});

it('writes DTSTART in UTC with a trailing Z', function () {
    // 19:00 Berlin (Sommerzeit, +02:00) entspricht 17:00 UTC.
    $out = ics()->event(title: 'X', start: CarbonImmutable::parse('2026-07-15 19:00', 'Europe/Berlin'));

    expect($out)->toContain('DTSTART:20260715T170000Z');
});

it('defaults the end to two hours after the start', function () {
    $out = ics()->event(title: 'X', start: CarbonImmutable::parse('2026-07-15 17:00', 'UTC'));

    expect($out)->toContain('DTSTART:20260715T170000Z')
        ->toContain('DTEND:20260715T190000Z');
});

it('uses an explicit end when given', function () {
    $out = ics()->event(
        title: 'X',
        start: CarbonImmutable::parse('2026-07-15 17:00', 'UTC'),
        end: CarbonImmutable::parse('2026-07-15 18:30', 'UTC'),
    );

    expect($out)->toContain('DTEND:20260715T183000Z');
});

it('escapes commas, semicolons and newlines', function () {
    $out = ics()->event(
        title: 'A, B; C',
        start: CarbonImmutable::parse('2026-07-15 17:00', 'UTC'),
        description: "Line1\nLine2",
    );

    expect($out)->toContain('SUMMARY:A\, B\; C')
        ->toContain('DESCRIPTION:Line1\nLine2');
});

it('keeps text containing a less-than sign instead of stripping it like HTML', function () {
    $out = ics()->event(
        title: 'Wir <3 Bitcoin',
        start: CarbonImmutable::parse('2026-07-15 17:00', 'UTC'),
        description: 'Bring less than <10 EUR mit',
    );

    expect($out)->toContain('SUMMARY:Wir <3 Bitcoin')
        ->toContain('DESCRIPTION:Bring less than <10 EUR mit');
});

it('normalises CRLF and bare CR to an escaped newline without leaving a raw carriage return', function () {
    $out = ics()->event(
        title: 'X',
        start: CarbonImmutable::parse('2026-07-15 17:00', 'UTC'),
        description: "Line1\r\nLine2\rLine3",
    );

    expect($out)->toContain('DESCRIPTION:Line1\nLine2\nLine3')
        // Nur die VCALENDAR-Struktur-CRs (vor \n) dürfen vorkommen, kein bare CR im Wert.
        ->and(preg_match('/\r(?!\n)/', $out))->toBe(0);
});

it('omits location and description when empty or null', function () {
    $out = ics()->event(
        title: 'X',
        start: CarbonImmutable::parse('2026-07-15 17:00', 'UTC'),
        location: '',
        description: null,
    );

    expect($out)->not->toContain('LOCATION:')
        ->not->toContain('DESCRIPTION:');
});
