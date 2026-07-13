{{--
    Globales Bild-Crop-Modal für die Editoren (Meetup/Kurs/Referent). Einmal im
    Layout eingebettet; die Editoren teilen es sich über den `key` (siehe
    HandlesImageUpload). Als <flux:modal> umgesetzt, damit es KORREKT über dem
    bereits offenen Editor-Sheet stapelt — ein eigenes Popover/z-index verliert
    gegen Flux' Modal-Stacking (im Web-Test verifiziert). Verhalten in der
    Alpine-Komponente `imageCropper` (resources/js/app.js): öffnet auf
    `image-crop-open`, initialisiert cropperjs auf `cropImg` und gibt das Ergebnis
    per `image-cropped` an den passenden Editor zurück.
--}}
<div x-data="imageCropper" @image-crop-open.window="show($event.detail)">
    <flux:modal
        name="image-cropper"
        @close="teardown()"
        class="w-full max-w-3xl"
        aria-label="{{ __('Bild zuschneiden') }}"
    >
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Bild zuschneiden') }}</flux:heading>

            {{-- Feste Höhe: cropperjs misst den Container beim Init (0px → kaputt). --}}
            <div class="flex items-center justify-center overflow-hidden" style="height: 60vh">
                <img id="image-cropper-img" :src="src" alt="" class="block max-w-full" style="max-height: 60vh"/>
            </div>

            <div class="flex items-center justify-end gap-2">
                <flux:button x-on:click="$flux.modal('image-cropper').close()" type="button" variant="ghost" class="cursor-pointer">
                    {{ __('Abbrechen') }}
                </flux:button>
                <flux:button x-on:click="confirm()" type="button" variant="primary" icon="check" class="cursor-pointer">
                    {{ __('Zuschneiden') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
