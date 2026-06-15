{{-- Sprach-Auswahl (Onboarding + Profil). Bei 8 Sprachen ist ein Select
     kompakter als ein segmentierter Radio-Block; native Sprachnamen. --}}
<flux:select {{ $attributes }} :label="__('Sprache')">
    <flux:select.option value="de">Deutsch</flux:select.option>
    <flux:select.option value="en">English</flux:select.option>
    <flux:select.option value="es">Español</flux:select.option>
    <flux:select.option value="hu">Magyar</flux:select.option>
    <flux:select.option value="lv">Latviešu</flux:select.option>
    <flux:select.option value="nl">Nederlands</flux:select.option>
    <flux:select.option value="pl">Polski</flux:select.option>
    <flux:select.option value="pt">Português</flux:select.option>
</flux:select>
