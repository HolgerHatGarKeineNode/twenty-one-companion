<?php

use App\Services\AppPreferences;
use Einundzwanzig\Push\Push;
use Flux\Flux;
use Livewire\Component;

/**
 * Chat notifications — the app-only push switch, injected into the package settings hub
 * (P2, `config('group.settings')`).
 *
 * A Livewire component of its own for the same reason as the region section next to it: the
 * value is server state and it saves on change. Lifted verbatim from the deleted
 * `pages/profile/⚡index.blade.php`, including the reasoning below, which is a measurement.
 */
new class extends Component
{
    public bool $pushEnabled = false;

    public function mount(AppPreferences $preferences): void
    {
        // Deliberately ONLY the setting, without `notificationPermissionGranted()`: the
        // switch can therefore read ON although the user declined the OS dialog (known, see
        // plans/PUSH-NOTIFICATIONS.md). The obvious fix is worse than the fault — bridge
        // calls during page construction are unreliable (§4), and a wrong `false` from there
        // silently switches push off for the user.
        $this->pushEnabled = $preferences->pushEnabled();
    }

    /**
     * Scheduling and unscheduling the worker is done by the push-sync partial in the layout:
     * it needs the pubkey from `localStorage`, which PHP does not know. Switching OFF stops
     * the worker so that no background activity remains (battery).
     */
    public function updatedPushEnabled(AppPreferences $preferences): void
    {
        $preferences->setPushEnabled($this->pushEnabled);

        if ($this->pushEnabled) {
            (new Push)->requestNotificationPermission();
        } else {
            (new Push)->sync();
        }

        Flux::toast(text: __('Gespeichert.'), variant: 'success');
    }
}; ?>

<section aria-labelledby="settings-push">
    <flux:heading id="settings-push" level="2" size="sm" class="mb-2 text-muted">{{ __('Benachrichtigungen') }}</flux:heading>
    <div class="surface-card p-4">
        <flux:switch
            wire:model.live="pushEnabled"
            :label="__('Chat-Benachrichtigungen')"
            :description="__('Prüft im Hintergrund auf neue Nachrichten. Aus = keine Hintergrundaktivität, das schont den Akku.')"
        />
    </div>
</section>
