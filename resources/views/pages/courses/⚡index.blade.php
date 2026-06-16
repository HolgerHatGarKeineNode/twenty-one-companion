<?php

use App\Data\Portal\CourseData;
use App\Data\Portal\LecturerData;
use App\Livewire\PortalPage;
use App\Services\PortalApi;
use App\Services\PortalAuth;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;

new #[Layout('layouts::mobile', ['title' => 'Kurse', 'heading' => 'Kurse'])] class extends PortalPage {
    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $tab = 'kurse';

    /**
     * „Meine Kurse“ nur für eingeloggte Referenten (Profil aus dem
     * lokalen Cache, kein zusätzlicher HTTP-Call beim Rendern).
     */
    #[Computed]
    public function isLecturer(): bool
    {
        $auth = app(PortalAuth::class);

        return $auth->hasToken() && (bool) ($auth->cachedProfile()['is_lecturer'] ?? false);
    }

    /**
     * Alle Kurse, nach Suchbegriff (Kurs-/Referentenname) gefiltert;
     * Kurse mit kommendem Termin zuerst (nach Datum), danach alphabetisch.
     *
     * @return Collection<int, CourseData>
     */
    #[Computed]
    public function courses(): Collection
    {
        return $this->filterCourses(app(PortalApi::class)->courses(withDetails: true));
    }

    /**
     * Alle Referenten, nach Suchbegriff (Name/Untertitel) gefiltert;
     * wie im Portal: Referenten mit dem nächsten kommenden Kurs-Event
     * zuerst (frühestes Datum vorn), danach alphabetisch.
     *
     * @return Collection<int, LecturerData>
     */
    #[Computed]
    public function lecturers(): Collection
    {
        $search = mb_strtolower(trim($this->search));

        return app(PortalApi::class)
            ->lecturers(withDetails: true)
            ->filter(fn (LecturerData $lecturer): bool => $search === ''
                || str_contains(mb_strtolower($lecturer->name), $search)
                || str_contains(mb_strtolower($lecturer->subtitleOrNull() ?? ''), $search))
            ->sortBy(fn (LecturerData $lecturer): array => [
                $lecturer->nextEvent() === null,
                $lecturer->nextEvent()?->getTimestamp() ?? 0,
                mb_strtolower($lecturer->name),
            ])
            ->values();
    }

    /**
     * Vom Nutzer selbst erstellte Kurse (Tab „Meine“).
     *
     * @return Collection<int, CourseData>
     */
    #[Computed]
    public function myCourses(): Collection
    {
        return $this->filterCourses(app(PortalApi::class)->myCourses());
    }

    /**
     * @param  Collection<int, CourseData>  $courses
     * @return Collection<int, CourseData>
     */
    protected function filterCourses(Collection $courses): Collection
    {
        $search = mb_strtolower(trim($this->search));

        return $courses
            ->filter(fn (CourseData $course): bool => $search === ''
                || str_contains(mb_strtolower($course->name), $search)
                || str_contains(mb_strtolower($course->lecturerOrNull()?->name ?? ''), $search))
            ->sortBy(fn (CourseData $course): array => [
                $course->nextEvent() === null,
                $course->nextEvent()?->getTimestamp() ?? 0,
                mb_strtolower($course->name),
            ])
            ->values();
    }
};
?>

<x-portal-page>
    <flux:tabs wire:model.live="tab" variant="segmented" class="w-full">
        <flux:tab name="kurse">{{ __('Kurse') }}</flux:tab>
        <flux:tab name="referenten">{{ __('Referenten') }}</flux:tab>
        @if ($this->isLecturer)
            <flux:tab name="meine">{{ __('Meine') }}</flux:tab>
        @endif
    </flux:tabs>

    <flux:input
        wire:model.live.debounce.300ms="search"
        type="search"
        icon="magnifying-glass"
        :placeholder="$tab === 'referenten' ? __('Referenten suchen …') : __('Kurs oder Referent suchen …')"
        clearable
    />

    @if ($tab === 'referenten')
        @if ($this->lecturers->isEmpty())
            <x-portal-empty-state icon="user" :heading="__('Keine Referenten gefunden')" :error-heading="__('Referenten nicht verfügbar')">
                <flux:text class="max-w-xs">{{ __('Versuche eine andere Suche.') }}</flux:text>
            </x-portal-empty-state>
        @else
            <div class="flex flex-col gap-3">
                @foreach ($this->lecturers as $lecturer)
                    <x-list-link-card
                        href="{{ route('lecturers.show', $lecturer->id) }}"
                        wire:key="lecturer-{{ $lecturer->id }}"
                    >
                        <x-meetup-avatar :logo="$lecturer->image ?: null" :name="$lecturer->name"/>
                        <span class="flex min-w-0 flex-col gap-0.5">
                            <span class="truncate font-semibold">{{ $lecturer->name }}</span>
                            @if ($lecturer->subtitleOrNull())
                                <flux:text class="truncate text-sm">{{ $lecturer->subtitleOrNull() }}</flux:text>
                            @endif
                            @if ($lecturer->futureEventsCount() > 0)
                                <flux:badge color="orange" size="sm" class="mt-1 w-fit">
                                    {{ trans_choice(':count kommender Termin|:count kommende Termine', $lecturer->futureEventsCount()) }}
                                </flux:badge>
                            @endif
                        </span>
                    </x-list-link-card>
                @endforeach
            </div>
        @endif
    @else
        @php($courses = $tab === 'meine' && $this->isLecturer ? $this->myCourses : $this->courses)

        @if ($courses->isEmpty())
            <x-portal-empty-state icon="academic-cap" :heading="$tab === 'meine' ? __('Keine eigenen Kurse') : __('Keine Kurse gefunden')" :error-heading="__('Kurse nicht verfügbar')">
                <flux:text class="max-w-xs">
                    {{ $tab === 'meine'
                        ? __('Du hast im Portal noch keine Kurse angelegt.')
                        : __('Versuche eine andere Suche.') }}
                </flux:text>
            </x-portal-empty-state>
        @else
            <div class="flex flex-col gap-3">
                @foreach ($courses as $course)
                    <x-list-link-card
                        href="{{ route('courses.show', $course->id) }}"
                        wire:key="{{ $tab }}-course-{{ $course->id }}"
                    >
                        <x-meetup-avatar :logo="$course->imageOrNull()" :name="$course->name"/>
                        <span class="flex min-w-0 flex-col gap-0.5">
                            <span class="truncate font-semibold">{{ $course->name }}</span>
                            @if ($course->lecturerOrNull())
                                <flux:text class="truncate text-sm">{{ $course->lecturerOrNull()->name }}</flux:text>
                            @endif
                            @if ($course->nextEvent())
                                <flux:badge color="orange" size="sm" class="mt-1 w-fit">
                                    {{ $course->nextEvent()->forDisplay()->translatedFormat('D, d. M · H:i') }}
                                </flux:badge>
                            @endif
                        </span>
                    </x-list-link-card>
                @endforeach
            </div>
        @endif
    @endif

</x-portal-page>
