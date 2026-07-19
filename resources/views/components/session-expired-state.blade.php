@props([
    'heading' => null,
    'minHeight' => 'min-h-[40dvh]',
])

{{-- Abgelaufene/ungültige Portal-Sitzung (HTTP 401 auf einen auth-Endpunkt):
     ehrlich als „Sitzung abgelaufen“ statt „Portal nicht erreichbar“, mit einem
     Reconnect-CTA in DENSELBEN Nostr-Login wie sonst (group.nostr-login → Handoff
     holt einen frischen Token). Voller Seitenwechsel (kein wire:navigate), weil
     group.* das eigene Chat-/Login-Layout lädt. Der bestehende Token wird BEWUSST
     nicht gelöscht (ponytail: kein Überraschungs-Logout) — der Reconnect überschreibt
     ihn beim erneuten Signieren. --}}
<x-empty-state icon="key" :heading="$heading ?? __('Sitzung abgelaufen')" :min-height="$minHeight">
    <flux:text class="max-w-xs">
        {{ __('Deine Portal-Sitzung ist abgelaufen. Bitte verbinde dich neu, um deine eigenen Inhalte zu sehen.') }}
    </flux:text>
    <flux:button :href="route('group.nostr-login')" variant="primary" icon="key" class="mt-2 cursor-pointer">
        {{ __('Neu verbinden') }}
    </flux:button>
</x-empty-state>
