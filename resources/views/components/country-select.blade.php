@props(['countries'])

{{-- Regionsauswahl (Onboarding + Profil): Länder mit Meetups + „Alle Länder“.
     variant="listbox" wie bei der Sprachauswahl: ein natives <select> klappt in
     der Android-WebView als System-Dialog auf, der das Dark-Theme ignoriert. --}}
<flux:select variant="listbox" {{ $attributes }}>
    @foreach ($countries as $option)
        <flux:select.option value="{{ $option['code'] }}">
            {{ \App\Services\CountryOptions::flagEmoji($option['code']) }} {{ $option['name'] }}
        </flux:select.option>
    @endforeach
    <flux:select.option value="">🌍 {{ __('Alle Länder') }}</flux:select.option>
</flux:select>
