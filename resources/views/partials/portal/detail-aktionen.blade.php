{{-- `config('group.portal_detail_actions')` — the host block at the end of one of the
     package's Portal detail pages (P4, D9).

     On the web this slot is a link INTO the Portal. In this app it is the opposite: the way
     into its OWN editors. The reason is the Portal TOKEN — creating and editing need one and
     the package has none. That makes exactly this place the one exception to D9's read-only
     rule, and it lives here instead of in the package.

     In scope: `$portalLink` always, plus `$meetupId`/`$meetupSlug`, `$courseId` or
     `$lecturerId` depending on the page.

     ── Why the sheets are mounted HERE ─────────────────────────────────────────────
     The editors otherwise hang in `layouts.mobile`, and the package pages run in the package
     layout (`group::einundzwanzig`) — where they do not exist. A button that opens a modal
     that is not on this page does visibly nothing. Mounted for connected users only: without
     a token no editor can save. --}}

@php($verbunden = app(\App\Services\PortalAuth::class)->hasToken())

<section class="surface-card p-6" data-portal-host-aktionen>
    <div class="flex flex-wrap gap-2">
        @if ($portalLink !== '' && $portalLink !== null)
            {{-- Through the affordance and not as an `<a>`: in the app a Portal link belongs
                 in the in-app browser (the user stays in the app), and the scheme check lives
                 there. --}}
            <flux:button size="sm" variant="ghost" icon="arrow-top-right-on-square"
                         wire:click="openLink(@js($portalLink))"
                         data-portal-host-link>
                {{ __('Im Portal öffnen') }}
            </flux:button>
        @endif

        @if ($verbunden && isset($meetupId) && $meetupId !== null)
            <flux:button size="sm" variant="ghost" icon="pencil-square" class="cursor-pointer"
                         data-portal-host-editor="meetup"
                         x-on:click="$haptic('light'); $flux.modal('create-meetup').show(); Livewire.dispatch('open-meetup-editor', { id: {{ (int) $meetupId }} })">
                {{ __('Bearbeiten') }}
            </flux:button>
            <flux:button size="sm" variant="ghost" icon="plus" class="cursor-pointer"
                         data-portal-host-editor="event"
                         x-on:click="$haptic('medium'); $flux.modal('create-event').show(); Livewire.dispatch('open-event-editor', {{ Js::from(['meetupId' => (int) $meetupId]) }})">
                {{ __('Termin anlegen') }}
            </flux:button>
        @endif

        @if ($verbunden && isset($courseId))
            <flux:button size="sm" variant="ghost" icon="pencil-square" class="cursor-pointer"
                         data-portal-host-editor="course"
                         x-on:click="$haptic('light'); $flux.modal('create-course').show(); Livewire.dispatch('open-course-editor', { id: {{ (int) $courseId }} })">
                {{ __('Bearbeiten') }}
            </flux:button>
            {{-- „Kurs-Event anlegen" (P5): stood on this app's own COURSE page until P4,
                 and that page is deleted with P5. The sheet is already mounted below;
                 without this button the way from a course to a new course date would have
                 disappeared — reachable only through „Ich › Meine Inhalte › Kurse". --}}
            <flux:button size="sm" variant="ghost" icon="calendar-days" class="cursor-pointer"
                         data-portal-host-editor="course-event"
                         x-on:click="$haptic('light'); $flux.modal('create-course-event').show(); Livewire.dispatch('open-course-event-editor', { courseId: {{ (int) $courseId }} })">
                {{ __('Kurs-Event anlegen') }}
            </flux:button>
        @endif

        @if ($verbunden && isset($lecturerId))
            <flux:button size="sm" variant="ghost" icon="pencil-square" class="cursor-pointer"
                         data-portal-host-editor="lecturer"
                         x-on:click="$haptic('light'); $flux.modal('create-lecturer').show(); Livewire.dispatch('open-lecturer-editor', { id: {{ (int) $lecturerId }} })">
                {{ __('Bearbeiten') }}
            </flux:button>
        @endif
    </div>

    {{-- A notice instead of a mute button: whoever is not connected does not simply see no
         editor, he learns why. The connection itself lives in the settings (one place, not
         two). --}}
    @if (! $verbunden)
        <flux:text class="mt-3 text-sm text-muted" data-portal-host-hinweis>
            {{ __('Zum Bearbeiten im Portal anmelden — in den Einstellungen unter „Portal-Verbindung".') }}
        </flux:text>
    @endif
</section>

@if ($verbunden)
    {{-- The sheets themselves. They own their modal names (`create-meetup`, `create-event`,
         `create-course`, `create-lecturer`) and are opened by the buttons above. --}}
    @if (isset($meetupId) && $meetupId !== null)
        <livewire:meetup-editor />
        <livewire:meetup-leaders />
        <livewire:event-editor />
        <livewire:venue-editor />
        <livewire:city-editor />
    @endif
    @if (isset($courseId))
        <livewire:course-editor />
        <livewire:course-event-editor />
    @endif
    @if (isset($lecturerId))
        <livewire:lecturer-editor />
    @endif

    {{-- The shared crop overlay: the editors with a logo/avatar crop their natively chosen
         image here (see `HandlesImageUpload`). Without it choosing an image in the editor
         would have no effect. --}}
    <x-image-cropper-overlay />
@endif
