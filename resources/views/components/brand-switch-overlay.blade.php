@php
    use App\Support\Brand;
@endphp

{{--
    Vollbild-Zelebrierung beim Regionswechsel: lauscht auf das Livewire-Event
    `brand-changed` (dispatch im Profil/Onboarding), zeigt kurz das Bitcoin-
    Roundel + die neue Wortmarke gross an und blendet wieder aus. Alle
    Wortmarken sind vorgerendert; nur die aktive wird per Slug eingeblendet.
--}}
<div
    x-data="{
        visible: false,
        animating: false,
        slug: null,
        label: null,
        _t: null,
        celebrate(detail) {
            this.slug = detail.slug;
            this.label = detail.label;
            this.visible = true;
            this.animating = false;
            this.$nextTick(() => {
                this.animating = true;
                if (window.$haptic) { window.$haptic('success'); }
            });
            clearTimeout(this._t);
            this._t = setTimeout(() => { this.animating = false; this.visible = false; }, 2000);
        },
    }}
    @brand-changed.window="celebrate($event.detail)"
    x-show="visible"
    x-cloak
    x-transition.opacity.duration.350ms
    @click="visible = false"
    class="fixed inset-0 z-[100] flex items-center justify-center bg-zinc-950/95 backdrop-blur-sm"
    style="display: none"
    role="status"
    aria-live="polite"
>
    <div class="flex w-full max-w-md flex-col items-center gap-7 px-10">
        <div class="brand-switch-mark" :class="{ 'brand-switch-mark--in': animating }">
            <x-brand-bitcoin class="h-16 w-16 drop-shadow-[0_0_24px_rgba(247,147,26,0.45)]"/>
        </div>

        <div class="brand-switch-word w-full text-white" :class="{ 'brand-switch-word--in': animating }">
            @foreach (Brand::cases() as $b)
                <div x-show="slug === '{{ $b->value }}'" class="w-full" aria-hidden="true">
                    <x-dynamic-component
                        :component="$b->wordmarkComponent()"
                        class="h-auto w-full"
                    />
                </div>
            @endforeach
        </div>

        <p class="brand-switch-caption text-sm tracking-wide text-zinc-400" :class="{ 'brand-switch-word--in': animating }">
            {{ __('Willkommen bei') }} <span class="font-semibold text-zinc-200" x-text="label"></span>
        </p>
    </div>
</div>
