@props([
    'icon' => 'lock-closed',
    'heading' => null,
    'text' => null,
    'minHeight' => 'min-h-[40dvh]',
])

{{-- Auth-Gate für Schreib-Features: zeigt den Inhalt (Formular, Aktion) nur
     mit gültigem Portal-Token. Unverbundene Nutzer sehen stattdessen einen
     „Mit Portal verbinden“-CTA, der zur Profil-Seite mit dem Login-Flow
     führt — statt eines Formulars, das ohne Token ohnehin 401 liefern würde. --}}
@if (app(\App\Services\PortalAuth::class)->hasToken())
    {{ $slot }}
@else
    <div class="flex {{ $minHeight }} flex-col items-center justify-center gap-3 px-4 text-center">
        <div class="relative mb-1">
            <span class="absolute -inset-4 rounded-full bg-brand-500/20 blur-2xl" aria-hidden="true"></span>
            <span class="relative flex size-14 items-center justify-center rounded-tile border border-zinc-200 bg-white text-brand-600 shadow-card dark:border-zinc-800 dark:bg-zinc-900 dark:text-brand-400 dark:shadow-none">
                <flux:icon :name="$icon" class="size-7"/>
            </span>
        </div>
        <flux:heading size="lg">{{ $heading ?? __('Mit Portal verbinden') }}</flux:heading>
        <flux:text class="max-w-xs">
            {{ $text ?? __('Verbinde die App mit deinem Einundzwanzig-Portal-Konto, um diese Funktion zu nutzen.') }}
        </flux:text>
        <flux:button :href="route('profile')" wire:navigate variant="primary" icon="key" class="mt-1 cursor-pointer">
            {{ __('Konto verbinden') }}
        </flux:button>
    </div>
@endif
