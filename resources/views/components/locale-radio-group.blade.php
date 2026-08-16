{{-- Sprach-Auswahl (Onboarding + Profil). Bei 8 Sprachen ist ein Select
     kompakter als ein segmentierter Radio-Block; native Sprachnamen.

     variant="listbox" bewusst statt des Default-Selects: die Android-WebView
     rendert die Optionsliste eines nativen <select> als System-Dialog, und der
     zieht sein Theme aus dem App-Context statt aus dem CSS — in der dunklen App
     klappte damit eine weiße Liste auf. Der Listbox-Variante liegt reines
     HTML zugrunde, sie folgt also dem Dark-Theme. --}}
<flux:select variant="listbox" {{ $attributes }} :label="__('Sprache')">
    <flux:select.option value="de">Deutsch</flux:select.option>
    <flux:select.option value="en">English</flux:select.option>
    <flux:select.option value="es">Español</flux:select.option>
    <flux:select.option value="hu">Magyar</flux:select.option>
    <flux:select.option value="lv">Latviešu</flux:select.option>
    <flux:select.option value="nl">Nederlands</flux:select.option>
    <flux:select.option value="pl">Polski</flux:select.option>
    <flux:select.option value="pt">Português</flux:select.option>
</flux:select>
