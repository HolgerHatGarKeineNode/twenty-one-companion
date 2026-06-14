<?php

use App\Data\Portal\CourseData;
use App\Data\Portal\LecturerData;
use App\Data\Portal\MapMeetupData;
use App\Services\PortalApi;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Globale Suche (Phase 2.3): ein Such-Sheet im „Command-Palette“-Stil, das
 * Meetups, Kurse und Referenten übergreifend durchsucht und direkt ins Detail
 * springt. In das Layout eingebettet und über die Lupe im Header (Flux-Modal
 * `global-search`) geöffnet. Gefiltert wird in-memory auf den gecachten
 * Volllisten der PortalApi — kein API-Treffer pro Tastendruck.
 */
new class extends Component
{
    public string $term = '';

    /** Mindestlänge, ab der gesucht wird. */
    private const MIN_TERM = 2;

    /** Treffer pro Kategorie. */
    private const PER_GROUP = 6;

    protected function normalizedTerm(): string
    {
        return mb_strtolower(trim($this->term));
    }

    public function hasQuery(): bool
    {
        return mb_strlen($this->normalizedTerm()) >= self::MIN_TERM;
    }

    /**
     * Gemeinsame Such-Mechanik der Kategorien: ohne gültige Query leer (ohne
     * die Liste zu laden), sonst filtern, optional sortieren und auf
     * PER_GROUP kürzen. Die Liste kommt als Closure, damit der teure
     * PortalApi-Read bei zu kurzem Term gar nicht erst läuft.
     *
     * @template TValue
     * @param  callable(): Collection<int, TValue>  $source
     * @param  callable(TValue): bool  $matches
     * @param  (callable(TValue): string)|null  $sortBy
     * @return Collection<int, TValue>
     */
    protected function search(callable $source, callable $matches, ?callable $sortBy = null): Collection
    {
        if (! $this->hasQuery()) {
            return collect();
        }

        $results = $source()->filter($matches);

        if ($sortBy !== null) {
            $results = $results->sortBy($sortBy);
        }

        return $results->take(self::PER_GROUP)->values();
    }

    /**
     * @return Collection<int, MapMeetupData>
     */
    #[Computed]
    public function meetups(): Collection
    {
        $term = $this->normalizedTerm();

        return $this->search(
            fn (): Collection => app(PortalApi::class)->mapMeetups(withIntro: false, withLogos: true),
            fn (MapMeetupData $meetup): bool => str_contains(mb_strtolower($meetup->name), $term)
                || str_contains(mb_strtolower($meetup->city), $term),
            fn (MapMeetupData $meetup): string => mb_strtolower($meetup->name),
        );
    }

    /**
     * @return Collection<int, CourseData>
     */
    #[Computed]
    public function courses(): Collection
    {
        $term = $this->normalizedTerm();

        return $this->search(
            // withDetails: true hebt das serverseitige 10-Einträge-Limit auf
            // (sonst durchsucht die globale Suche nur die ersten 10 Kurse) und
            // teilt zugleich den Cache-Key mit der Kurs-Index-Seite.
            fn (): Collection => app(PortalApi::class)->courses(withDetails: true),
            fn (CourseData $course): bool => str_contains(mb_strtolower($course->name), $term),
        );
    }

    /**
     * @return Collection<int, LecturerData>
     */
    #[Computed]
    public function lecturers(): Collection
    {
        $term = $this->normalizedTerm();

        return $this->search(
            // withDetails: true wie bei courses(): ohne das Flag liefert das
            // Portal nur 10 Referenten, die globale Suche übersähe den Rest.
            fn (): Collection => app(PortalApi::class)->lecturers(withDetails: true),
            fn (LecturerData $lecturer): bool => str_contains(mb_strtolower($lecturer->name), $term),
        );
    }

    #[Computed]
    public function isEmpty(): bool
    {
        return $this->meetups->isEmpty()
            && $this->courses->isEmpty()
            && $this->lecturers->isEmpty();
    }
};
?>

<flux:modal name="global-search" class="!rounded-sheet w-full max-w-md self-start sm:mt-12">
    <div class="flex flex-col gap-4">
        <flux:heading size="lg">{{ __('Suche') }}</flux:heading>

        <flux:input
            wire:model.live.debounce.300ms="term"
            type="search"
            icon="magnifying-glass"
            :placeholder="__('Meetups, Kurse, Referenten …')"
            autofocus
            clearable
        />

        @if (! $this->hasQuery())
            <flux:text class="py-6 text-center text-sm">
                {{ __('Tippe mindestens zwei Zeichen, um zu suchen.') }}
            </flux:text>
        @elseif ($this->isEmpty)
            <div class="flex flex-col items-center gap-2 py-8 text-center">
                <flux:icon name="magnifying-glass" class="size-8 text-zinc-400"/>
                <flux:text class="text-sm">{{ __('Keine Treffer für „:term“.', ['term' => $term]) }}</flux:text>
            </div>
        @else
            <div class="-mx-2 flex max-h-[60dvh] flex-col gap-4 overflow-y-auto px-2">
                @if ($this->meetups->isNotEmpty())
                    <div class="flex flex-col gap-1">
                        <flux:text class="px-1 text-xs font-semibold tracking-wide text-zinc-500 uppercase">{{ __('Meetups') }}</flux:text>
                        @foreach ($this->meetups as $meetup)
                            <a
                                href="{{ route('meetups.show', $meetup->slug()) }}"
                                wire:navigate
                                x-on:click="$haptic('light')"
                                wire:key="search-meetup-{{ $meetup->slug() }}"
                                class="pressable flex items-center gap-3 rounded-tile px-2 py-2 active:bg-zinc-100 dark:active:bg-zinc-800"
                            >
                                <x-meetup-avatar :logo="$meetup->logo" :name="$meetup->name" class="size-9"/>
                                <span class="flex min-w-0 flex-col">
                                    <span class="truncate text-sm font-semibold">{{ $meetup->name }}</span>
                                    <flux:text class="truncate text-xs">{{ $meetup->city }} · {{ $meetup->country }}</flux:text>
                                </span>
                            </a>
                        @endforeach
                    </div>
                @endif

                @if ($this->courses->isNotEmpty())
                    <div class="flex flex-col gap-1">
                        <flux:text class="px-1 text-xs font-semibold tracking-wide text-zinc-500 uppercase">{{ __('Kurse') }}</flux:text>
                        @foreach ($this->courses as $course)
                            <a
                                href="{{ route('courses.show', $course->id) }}"
                                wire:navigate
                                x-on:click="$haptic('light')"
                                wire:key="search-course-{{ $course->id }}"
                                class="pressable flex items-center gap-3 rounded-tile px-2 py-2 active:bg-zinc-100 dark:active:bg-zinc-800"
                            >
                                <flux:icon name="academic-cap" class="size-5 shrink-0 text-zinc-400"/>
                                <span class="truncate text-sm font-semibold">{{ $course->name }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif

                @if ($this->lecturers->isNotEmpty())
                    <div class="flex flex-col gap-1">
                        <flux:text class="px-1 text-xs font-semibold tracking-wide text-zinc-500 uppercase">{{ __('Referenten') }}</flux:text>
                        @foreach ($this->lecturers as $lecturer)
                            <a
                                href="{{ route('lecturers.show', $lecturer->id) }}"
                                wire:navigate
                                x-on:click="$haptic('light')"
                                wire:key="search-lecturer-{{ $lecturer->id }}"
                                class="pressable flex items-center gap-3 rounded-tile px-2 py-2 active:bg-zinc-100 dark:active:bg-zinc-800"
                            >
                                <flux:icon name="user" class="size-5 shrink-0 text-zinc-400"/>
                                <span class="truncate text-sm font-semibold">{{ $lecturer->name }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </div>
</flux:modal>
