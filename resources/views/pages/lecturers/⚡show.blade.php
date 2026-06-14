<?php

use App\Data\Portal\LecturerDetailData;
use App\Data\Portal\MyLecturerData;
use App\Livewire\PortalPage;
use App\Services\PortalApi;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;

new #[Layout('layouts::mobile', ['title' => 'Referent', 'heading' => 'Referent', 'back' => '/courses?tab=referenten'])] class extends PortalPage {
    public int $id;

    public function mount(int $id): void
    {
        $this->id = $id;
    }

    #[Computed]
    public function lecturer(): ?LecturerDetailData
    {
        return app(PortalApi::class)->lecturer($this->id);
    }

    /**
     * Externe Links des Referenten als [Label => URL] für die Link-Liste.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function links(): array
    {
        return $this->lecturer?->socialLinks() ?? [];
    }

    /**
     * Gehört dieses Profil dem verbundenen Nutzer? (netzwerkfrei über die
     * gecachten eigenen Referenten — ohne Token leer.) Schaltet die
     * Bearbeiten-Affordance frei (Phase 7.4).
     */
    #[Computed]
    public function isOwner(): bool
    {
        return app(PortalApi::class)
            ->myLecturers()
            ->contains(fn (MyLecturerData $lecturer): bool => $lecturer->id === $this->id);
    }
};
?>

<x-portal-page>
    @if ($this->lecturer === null)
        <x-portal-empty-state icon="user" :heading="__('Referent nicht gefunden')" min-height="min-h-[60dvh]">
            <flux:text class="max-w-xs">
                {{ __('Dieses Profil existiert nicht mehr.') }}
            </flux:text>
            <flux:button :href="route('courses')" wire:navigate icon="arrow-left" size="sm">
                {{ __('Zu den Kursen') }}
            </flux:button>
        </x-portal-empty-state>
    @else
        <section class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-start gap-4">
                <x-meetup-avatar :logo="$this->lecturer->image ?: null" :name="$this->lecturer->name" size="xl"/>
                <div class="min-w-0 flex-1">
                    <flux:heading size="xl" level="1">{{ $this->lecturer->name }}</flux:heading>
                    @if ($this->lecturer->subtitle)
                        <flux:text class="mt-1">{{ $this->lecturer->subtitle }}</flux:text>
                    @endif
                </div>
            </div>
            @if ($this->isOwner)
                {{-- Bearbeiten für das eigene Profil (Phase 7.4). --}}
                <div class="mt-4">
                    <flux:button
                        size="sm"
                        variant="ghost"
                        icon="pencil-square"
                        x-on:click="$haptic('light'); $flux.modal('create-lecturer').show(); Livewire.dispatch('open-lecturer-editor', { id: {{ $this->lecturer->id }} })"
                        class="cursor-pointer"
                    >
                        {{ __('Bearbeiten') }}
                    </flux:button>
                </div>
            @endif
        </section>

        @if ($this->lecturer->introHtml() || $this->lecturer->descriptionHtml())
            <section class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <flux:heading size="lg" level="2">{{ __('Über den Referenten') }}</flux:heading>
                @if ($this->lecturer->introHtml())
                    <div class="markdown mt-3 text-sm">
                        {!! $this->lecturer->introHtml() !!}
                    </div>
                @endif
                @if ($this->lecturer->descriptionHtml())
                    <div class="markdown mt-3 text-sm">
                        {!! $this->lecturer->descriptionHtml() !!}
                    </div>
                @endif
            </section>
        @endif

        @if ($this->lecturer->courses !== [])
            <section class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <flux:heading size="lg" level="2">{{ __('Kurse') }}</flux:heading>
                <div class="mt-3 flex flex-col gap-3">
                    @foreach ($this->lecturer->courses as $course)
                        <x-list-link-card
                            href="{{ route('courses.show', $course->id) }}"
                            wire:key="course-{{ $course->id }}"
                        >
                            <x-meetup-avatar :logo="$course->imageOrNull()" :name="$course->name"/>
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <span class="truncate font-semibold">{{ $course->name }}</span>
                                @if ($course->nextEvent())
                                    <flux:badge color="orange" size="sm" class="mt-1 w-fit">
                                        {{ $course->nextEvent()->forDisplay()->translatedFormat('D, d. M · H:i') }}
                                    </flux:badge>
                                @endif
                            </span>
                        </x-list-link-card>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($this->links !== [])
            <section class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <flux:heading size="lg" level="2">{{ __('Links') }}</flux:heading>
                <div class="mt-3 flex flex-col gap-2">
                    @foreach ($this->links as $label => $url)
                        <button
                            type="button"
                            wire:click="openLink({{ Js::from($url) }})"
                            wire:key="link-{{ $label }}"
                            class="flex cursor-pointer items-center gap-3 rounded-xl border border-zinc-200 px-4 py-3 text-start transition-colors active:bg-zinc-100 dark:border-zinc-800 dark:active:bg-zinc-800"
                        >
                            <flux:icon name="link" class="size-5 shrink-0 text-zinc-400"/>
                            <span class="flex min-w-0 flex-col">
                                <span class="font-semibold">{{ $label }}</span>
                                <flux:text class="truncate text-sm">{{ $url }}</flux:text>
                            </span>
                        </button>
                    @endforeach
                </div>
            </section>
        @endif
    @endif

</x-portal-page>
