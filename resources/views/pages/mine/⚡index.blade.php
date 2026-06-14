<?php

use App\Data\Portal\MeetupData;
use App\Livewire\PortalPage;
use App\Services\PortalApi;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;

/**
 * „Meine Inhalte“-Hub (Phase 2.7): bündelt die eigenen Meetups, Termine, Orte
 * und Kurse als zentrale Anlaufstelle für alle CRUD-Flows. Auth-gated über
 * <x-requires-portal> — ohne Portal-Token zeigt die Seite den Verbinden-CTA.
 * Die Zähler werden nur innerhalb des Gates gelesen (sonst liefen die
 * API-Calls in einen 401).
 */
new #[Layout('layouts::mobile', ['title' => 'Meine Inhalte', 'heading' => 'Meine Inhalte'])] class extends PortalPage
{
    /**
     * @return Collection<int, MeetupData>
     */
    #[Computed]
    public function myMeetups(): Collection
    {
        return app(PortalApi::class)->myMeetups();
    }

    #[Computed]
    public function myCoursesCount(): int
    {
        return app(PortalApi::class)->myCourses()->count();
    }

    /**
     * Die Hub-Sektionen als Einstieg in die eigenen Inhalte. Reihenfolge =
     * Anzeigereihenfolge; der Stagger-Index kommt aus dem $loop.
     *
     * @return list<array{icon: string, label: string, href: string, subtitle: string}>
     */
    #[Computed]
    public function sections(): array
    {
        $meetups = $this->myMeetups->count();
        $courses = $this->myCoursesCount;

        return [
            [
                'icon' => 'user-group',
                'label' => __('Meine Meetups'),
                'href' => route('meetups', ['tab' => 'meine']),
                'subtitle' => trans_choice('{0}Noch keine Meetups|{1}:count Meetup|[2,*]:count Meetups', $meetups, ['count' => $meetups]),
            ],
            [
                'icon' => 'calendar-days',
                'label' => __('Meine Termine'),
                'href' => route('events'),
                'subtitle' => __('Termine deiner Meetups verwalten'),
            ],
            [
                'icon' => 'building-office-2',
                'label' => __('Meine Orte & Städte'),
                'href' => route('mine.places'),
                'subtitle' => __('Veranstaltungsorte und Städte'),
            ],
            [
                'icon' => 'academic-cap',
                'label' => __('Meine Kurse & Referenten'),
                'href' => route('mine.teaching'),
                'subtitle' => trans_choice('{0}Noch keine Kurse|{1}:count Kurs|[2,*]:count Kurse', $courses, ['count' => $courses]),
            ],
        ];
    }
};
?>

<x-portal-page>
    <x-requires-portal :heading="__('Mit Portal verbinden')" :text="__('Verbinde dein Konto, um deine eigenen Meetups, Termine, Orte und Kurse zu verwalten.')">
        <div class="list-stagger flex flex-col gap-3">
            @foreach ($this->sections as $section)
                <x-list-link-card href="{{ $section['href'] }}" wire:key="mine-{{ $loop->index }}" style="--i: {{ $loop->index }}">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-tile bg-brand-500/10 text-brand-600 dark:text-brand-400">
                        <flux:icon :name="$section['icon']" class="size-6"/>
                    </span>
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <span class="font-semibold">{{ $section['label'] }}</span>
                        <flux:text class="text-sm">{{ $section['subtitle'] }}</flux:text>
                    </span>
                </x-list-link-card>
            @endforeach
        </div>
    </x-requires-portal>
</x-portal-page>
