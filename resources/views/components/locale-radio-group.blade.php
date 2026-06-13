{{-- Sprach-Auswahl (Onboarding + Profil). --}}
<flux:radio.group {{ $attributes }} :label="__('Sprache')" variant="segmented">
    <flux:radio value="de" label="Deutsch"/>
    <flux:radio value="en" label="English"/>
</flux:radio.group>
