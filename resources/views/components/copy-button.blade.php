@props([
    // Vollständiger Text, der in die Zwischenablage kopiert wird.
    'value',
    // Angezeigter (ggf. gekürzter) Text; Standard: der volle Wert.
    'display' => null,
    // aria-label / Tooltip der Kopier-Aktion.
    'label' => null,
])

@php($displayText = $display ?? $value)

{{--
    Ein-Tap-Kopieren mit Haptik und kurzem „Kopiert!"-Feedback. navigator.clipboard
    wie im 2FA-Setup; $haptic ist der globale Web-Helfer (No-op im Browser ohne Bridge).
    Als <button> mit .stop, damit es innerhalb klickbarer Zeilen nicht die Zeile mitauslöst.
--}}
<button
    type="button"
    x-data="{ copied: false }"
    x-on:click.stop="navigator.clipboard.writeText(@js($value)).then(() => { copied = true; $haptic('light'); setTimeout(() => copied = false, 1500); })"
    :class="copied && 'text-green-600 dark:text-green-400'"
    aria-label="{{ $label ?? __('Kopieren') }}"
    {{ $attributes->class('pressable flex w-fit max-w-full items-center gap-1 text-xs text-zinc-500 dark:text-zinc-400') }}
>
    <flux:icon name="clipboard-document" class="size-3.5 shrink-0"/>
    <span class="truncate font-mono" x-text="copied ? @js(__('Kopiert!')) : @js($displayText)">{{ $displayText }}</span>
</button>
