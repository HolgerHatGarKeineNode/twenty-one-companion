@props([
    'label',
    'currentUrl' => null,
    'hasSelected' => false,
    'shape' => 'square',
    'hint' => null,
])

@php
    $thumbClasses = $shape === 'circle' ? 'rounded-full' : 'rounded-tile';
@endphp

{{--
    Bild-Auswahl (Logo/Avatar) für die Editoren. Nutzt die Methoden des
    HandlesImageUpload-Concern der umgebenden Livewire-Komponente
    (captureImage / pickImage / clearSelectedImage) und zeigt eine Vorschau:
    frisch gewähltes Bild als Bestätigung, sonst das vorhandene Bild, sonst
    einen Platzhalter. Der eigentliche Upload läuft beim Speichern (zweistufig).
--}}
<div class="flex flex-col gap-2">
    <flux:label>{{ $label }}</flux:label>

    <div class="flex items-center gap-3 rounded-tile border border-zinc-200 p-3 dark:border-zinc-800">
        @if ($hasSelected)
            <span class="flex size-14 shrink-0 items-center justify-center {{ $thumbClasses }} bg-brand-500/10 text-brand-600 dark:text-brand-400">
                <flux:icon name="check-circle" class="size-7"/>
            </span>
            <div class="flex min-w-0 flex-1 flex-col">
                <flux:text class="font-semibold">{{ __('Bild ausgewählt') }}</flux:text>
                <flux:text size="sm">{{ __('Wird beim Speichern hochgeladen.') }}</flux:text>
            </div>
            <flux:button
                wire:click="clearSelectedImage"
                type="button"
                size="xs"
                variant="ghost"
                icon="x-mark"
                class="cursor-pointer"
            >
                {{ __('Entfernen') }}
            </flux:button>
        @else
            @if ($currentUrl)
                <img src="{{ $currentUrl }}" alt="" class="size-14 shrink-0 {{ $thumbClasses }} object-cover"/>
            @else
                <span class="flex size-14 shrink-0 items-center justify-center {{ $thumbClasses }} bg-zinc-100 text-zinc-400 dark:bg-zinc-800 dark:text-zinc-500">
                    <flux:icon name="photo" class="size-7"/>
                </span>
            @endif

            <div class="flex flex-1 flex-wrap gap-2">
                <flux:button
                    wire:click="captureImage"
                    type="button"
                    size="sm"
                    variant="ghost"
                    icon="camera"
                    x-on:click="$haptic('light')"
                    class="cursor-pointer"
                >
                    {{ __('Aufnehmen') }}
                </flux:button>
                <flux:button
                    wire:click="pickImage"
                    type="button"
                    size="sm"
                    variant="ghost"
                    icon="photo"
                    x-on:click="$haptic('light')"
                    class="cursor-pointer"
                >
                    {{ __('Galerie') }}
                </flux:button>
            </div>
        @endif
    </div>

    @if ($hint)
        <flux:text size="sm">{{ $hint }}</flux:text>
    @endif
</div>
