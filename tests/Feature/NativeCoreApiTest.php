<?php

declare(strict_types=1);

use Native\Mobile\Dialog;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Facades\Dialog as DialogFacade;
use Native\Mobile\Facades\File as FileFacade;
use Native\Mobile\File;
use Native\Mobile\Testing\FakeBridge;

/**
 * The Dialog and File APIs after `nativephp/mobile-dialog` and
 * `nativephp/mobile-file` were removed.
 *
 * NativePHP Mobile 4.x declares a `conflict` against both packages because their
 * functionality moved into the core package. Removing them is therefore not
 * optional — but nothing in this suite noticed whether the API survived the move:
 * all five call sites (`app/Livewire/PortalPage.php:61,69,75,81`,
 * `app/Livewire/Concerns/HandlesNativeConfirm.php:62`) drive `Dialog` through a
 * `shouldReceive` mock, and a mock answers just as happily when the real class is
 * gone. A completely broken v4 install left the whole host suite green.
 *
 * So these tests use no mock. They resolve the concrete classes out of the
 * container and drive them against `FakeBridge` — the in-process stand-in that
 * 4.x ships for exactly this — so the assertion is the payload that actually
 * reaches the native bridge, method name included.
 *
 * Device side stays out of scope: that the Kotlin handlers behind `Dialog.Alert`
 * and `File.Move` still exist and behave is P4's emulator run.
 */
it('resolves the dialog and file APIs out of the container without a plugin package', function (): void {
    expect(app(Dialog::class))->toBeInstanceOf(Dialog::class)
        ->and(app(File::class))->toBeInstanceOf(File::class);
});

it('sends an alert carrying the id and event that the confirm flow correlates on', function (): void {
    $bridge = FakeBridge::enable();

    // Same shape as HandlesNativeConfirm::confirmAction(): cancel first, confirm
    // last, tagged with a key so handleConfirmButton() can ignore foreign alerts —
    // and, like all five call sites, WITHOUT a trailing `->show()`. The alert goes
    // out through `PendingAlert::__destruct()` when the discarded temporary is
    // freed at the end of this statement. That auto-show is labelled a BC shim
    // upstream (vendor/nativephp/mobile/src/PendingAlert.php:139-145); an explicit
    // `->show()` here would keep the test green on the day the shim is dropped,
    // while every real call site went silent.
    DialogFacade::alert('Leiter entfernen', 'Wirklich entfernen?', ['Abbrechen', 'Entfernen'])
        ->id('remove-leader')
        ->event(ButtonPressed::class);

    $bridge->assertCalled('Dialog.Alert', fn (array $params): bool => $params['title'] === 'Leiter entfernen'
        && $params['message'] === 'Wirklich entfernen?'
        && $params['buttons'] === ['Abbrechen', 'Entfernen']
        && $params['id'] === 'remove-leader'
        && $params['event'] === ButtonPressed::class);
});

it('sends a toast for the successful retry feedback', function (): void {
    $bridge = FakeBridge::enable();

    // PortalPage::dehydrate() on a retry that succeeded.
    DialogFacade::toast('Aktualisiert.');

    $bridge->assertCalled('Dialog.Toast', fn (array $params): bool => $params['message'] === 'Aktualisiert.');
});

it('sends a file move across the bridge', function (): void {
    $bridge = FakeBridge::enable();
    $bridge->respondTo('File.Move', ['success' => true]);

    $moved = FileFacade::move('/tmp/from.jpg', '/tmp/to.jpg');

    expect($moved)->toBeTrue();
    $bridge->assertCalled('File.Move', fn (array $params): bool => $params['from'] === '/tmp/from.jpg'
        && $params['to'] === '/tmp/to.jpg');
});
