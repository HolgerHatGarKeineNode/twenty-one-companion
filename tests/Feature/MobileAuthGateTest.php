<?php

/**
 * The device gate's source of truth: the package layout marks a page as needing a key exactly
 * when its route carries `nostr.auth` (`routes/group.php` of einundzwanzig/group). On the
 * device `EnsureNostrAuth` lets every request through, so this mark is all the island has.
 */
beforeEach(function () {
    config(['nativephp-internal.running' => true]);
    completeOnboarding();
    withoutPortalToken();
});

it('marks the pages behind nostr.auth, and only those', function (string $path, bool $required) {
    $html = (string) $this->get($path)->assertOk()->getContent();

    expect(str_contains($html, '<meta name="nostr-auth-required"'))->toBe($required);
})->with([
    'start' => ['/start', false],
    'meetups' => ['/bereich/meetups', false],
    'courses' => ['/bereich/kurse', false],
    'articles' => ['/bereich/artikel', false],
    'ich' => ['/ich', false],
    'wallet' => ['/bereich/wallet', true],
    'chat' => ['/bereich/chat', true],
    'inbox' => ['/postfach', true],
    'room' => ['/rooms/08f1a277-7949-42ad-883e-6b8a32936154', true],
    'association' => ['/ich/verein', true],
]);
