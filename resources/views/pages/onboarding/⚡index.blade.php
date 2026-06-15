<?php

use App\Services\AppPreferences;
use App\Services\CountryOptions;
use App\Services\PortalAuth;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Native\Mobile\Facades\PushNotifications;

new #[Layout('layouts::mobile', ['title' => 'Willkommen', 'chrome' => false])] class extends Component {
    public int $step = AppPreferences::STEP_WELCOME;

    public string $locale = AppPreferences::DEFAULT_LOCALE;

    public string $country = AppPreferences::DEFAULT_COUNTRY;

    public function mount(AppPreferences $preferences): void
    {
        if ($preferences->isOnboarded()) {
            $this->redirectRoute('meetups', navigate: true);

            return;
        }

        // Resume mitten im Pager nach App-Neustart (Phase 3.6/3.7) + bereits
        // getroffene Sprach-/Regionswahl wiederherstellen.
        $this->step = min($preferences->onboardingStep(), AppPreferences::LAST_STEP);
        $this->locale = $preferences->locale();
        $this->country = $preferences->country();

        // Landeplatz nach dem Portal-Login-Deep-Link mitten im Onboarding:
        // Rückmeldung als Toast (Phase 3.3/3.4).
        if (session()->pull('portal-connected')) {
            Flux::toast(text: __('Mit dem Portal verbunden.'), variant: 'success');
        }

        if (session()->pull('portal-connect-failed')) {
            Flux::toast(text: __('Anmeldung fehlgeschlagen. Bitte versuche es erneut.'), variant: 'danger');
        }
    }

    /**
     * @return Collection<int, array{code: string, name: string}>
     */
    #[Computed]
    public function countries(): Collection
    {
        return app(CountryOptions::class)->all();
    }

    #[Computed]
    public function portalConnected(): bool
    {
        return app(PortalAuth::class)->hasToken();
    }

    /** Marke zur aktuell gewählten Region — für die Live-Wortmarke im Region-Schritt. */
    #[Computed]
    public function brand(): \App\Support\Brand
    {
        return \App\Support\Brand::forCountry($this->country);
    }

    public function next(AppPreferences $preferences, CountryOptions $countryOptions): void
    {
        // Schritt-spezifische Validierung + Persistenz der Auswahl, damit ein
        // Resume die bereits getroffene Wahl behält.
        if ($this->step === AppPreferences::STEP_LANGUAGE) {
            $this->validate(['locale' => ['required', 'in:'.implode(',', AppPreferences::SUPPORTED_LOCALES)]]);
            $preferences->setLocale($this->locale);
            app()->setLocale($this->locale);
        }

        if ($this->step === AppPreferences::STEP_REGION) {
            $this->validate(['country' => ['in:'.implode(',', $countryOptions->validCodes())]]);
            $preferences->setCountry($this->country);
        }

        $this->goToStep($this->nextStep(), $preferences);
    }

    public function back(AppPreferences $preferences): void
    {
        $this->goToStep(max($this->step - 1, AppPreferences::STEP_WELCOME), $preferences);
    }

    /** Optionalen Schritt (Portal/Push) ohne Aktion überspringen (Phase 3.4/3.5). */
    public function skip(AppPreferences $preferences): void
    {
        $this->goToStep($this->nextStep(), $preferences);
    }

    /**
     * Permission-Priming (Phase 3.5): Erst hier — nach der Erklärung — wird
     * der OS-Dialog ausgelöst. Im Web-/Test-Kontext ist enroll() ein
     * geguardeter No-op (kein nativephp_call), danach geht es weiter.
     */
    public function enableNotifications(AppPreferences $preferences): void
    {
        PushNotifications::enroll();

        $this->goToStep($this->nextStep(), $preferences);
    }

    public function finish(AppPreferences $preferences, CountryOptions $countryOptions): void
    {
        $this->validate([
            'locale' => ['required', 'in:'.implode(',', AppPreferences::SUPPORTED_LOCALES)],
            'country' => ['in:'.implode(',', $countryOptions->validCodes())],
        ]);

        $preferences->completeOnboarding($this->locale, $this->country);

        $this->redirectRoute('meetups', navigate: true);
    }

    /** Nächster Schritt, an der letzten Seite gedeckelt. */
    private function nextStep(): int
    {
        return min($this->step + 1, AppPreferences::LAST_STEP);
    }

    private function goToStep(int $step, AppPreferences $preferences): void
    {
        $this->step = $step;
        $preferences->setOnboardingStep($step);
    }
};
?>

<div class="pt-safe pb-safe px-safe relative flex min-h-dvh flex-col overflow-hidden">
    <div class="pointer-events-none absolute -top-24 start-1/2 size-72 -translate-x-1/2 rounded-full bg-brand-500/15 blur-3xl"></div>

    {{-- Fortschritts-Dots + Zurück (Phase 3.1/3.6). --}}
    <div class="relative flex h-14 items-center justify-between px-6">
        @if ($step > AppPreferences::STEP_WELCOME && $step < AppPreferences::STEP_DONE)
            <flux:button
                wire:click="back"
                variant="ghost"
                icon="chevron-left"
                size="sm"
                :aria-label="__('Zurück')"
                x-on:click="$haptic('light')"
                class="-ms-2 cursor-pointer"
            />
        @else
            <span></span>
        @endif

        <div class="flex items-center gap-2" role="presentation" aria-hidden="true">
            @for ($i = AppPreferences::STEP_WELCOME; $i <= AppPreferences::LAST_STEP; $i++)
                <span @class([
                    'h-1.5 rounded-full transition-all duration-300 ease-spring',
                    'w-6 bg-brand-500' => $i === $step,
                    'w-1.5 bg-zinc-300 dark:bg-zinc-700' => $i !== $step,
                ])></span>
            @endfor
        </div>

        <span class="w-8"></span>
    </div>

    <div class="flex flex-1 flex-col" wire:key="onboarding-step-{{ $step }}">
        <div class="step-enter flex flex-1 flex-col justify-center gap-8 p-6">
            @if ($step === AppPreferences::STEP_WELCOME)
                {{-- 3.2 Welcome mit Wertversprechen. --}}
                <div class="flex flex-col items-center gap-5 text-center">
                    <x-brand-wordmark class="h-auto w-full max-w-[16rem] text-zinc-900 dark:text-zinc-100"/>
                    <flux:text class="max-w-xs">
                        {{ __('Meetups, Termine und Kurse der Bitcoin-Community — direkt in deiner Tasche.') }}
                    </flux:text>
                </div>

                <div class="list-stagger flex flex-col gap-3">
                    @foreach ([
                        ['icon' => 'user-group', 'title' => __('Meetups finden'), 'text' => __('Entdecke die Community in deiner Region.')],
                        ['icon' => 'calendar-days', 'title' => __('Termine im Kalender'), 'text' => __('Verpasse kein Treffen mehr in deiner Nähe.')],
                        ['icon' => 'sparkles', 'title' => __('Eigene Community pflegen'), 'text' => __('Lege eigene Meetups und Termine an.')],
                    ] as $i => $tile)
                        <div class="surface-card flex items-center gap-4 p-4" style="--i: {{ $i }}">
                            <span class="flex size-11 shrink-0 items-center justify-center rounded-tile bg-brand-500/10 text-brand-600 dark:text-brand-400">
                                <flux:icon :name="$tile['icon']" class="size-6"/>
                            </span>
                            <div class="min-w-0">
                                <flux:heading size="md">{{ $tile['title'] }}</flux:heading>
                                <flux:text class="text-sm">{{ $tile['text'] }}</flux:text>
                            </div>
                        </div>
                    @endforeach
                </div>
            @elseif ($step === AppPreferences::STEP_LANGUAGE)
                <div class="flex flex-col gap-2 text-center">
                    <flux:heading size="xl" level="1">{{ __('Deine Sprache') }}</flux:heading>
                    <flux:text class="mx-auto max-w-xs">
                        {{ __('In welcher Sprache möchtest du die App nutzen?') }}
                    </flux:text>
                </div>
                <x-locale-radio-group wire:model="locale" class="self-center"/>
            @elseif ($step === AppPreferences::STEP_REGION)
                <div class="flex flex-col gap-2 text-center">
                    <flux:heading size="xl" level="1">{{ __('Deine Region') }}</flux:heading>
                    <flux:text class="mx-auto max-w-xs">
                        {{ __('Meetups und Termine werden zuerst für deine Region angezeigt. Das lässt sich jederzeit im Profil ändern.') }}
                    </flux:text>
                </div>
                {{-- Live-Wortmarke der gewählten Region: der wechselnde wire:key remountet
                     das Element bei Markenwechsel und spielt so die step-enter-Animation. --}}
                <div class="flex h-16 items-center justify-center" wire:key="onboarding-brand-{{ $this->brand->value }}">
                    <x-brand-wordmark :brand="$this->brand->value" class="step-enter h-auto w-full max-w-[14rem] text-zinc-900 dark:text-zinc-100"/>
                </div>
                <flux:field>
                    <flux:label>{{ __('Region') }}</flux:label>
                    <x-country-select :countries="$this->countries" wire:model.live="country"/>
                    <flux:error name="country"/>
                </flux:field>
            @elseif ($step === AppPreferences::STEP_PORTAL)
                {{-- 3.4 Portal-Verbindung im Flow, mit Lade-Indikator (3.3) aus der connect-Komponente. --}}
                <div class="flex flex-col gap-2 text-center">
                    <flux:heading size="xl" level="1">{{ __('Konto verbinden') }}</flux:heading>
                    <flux:text class="mx-auto max-w-xs">
                        {{ __('Verbinde dich mit dem Portal, um eigene Meetups, Termine und Kurse zu pflegen. Optional — die App bleibt auch ohne Konto nutzbar.') }}
                    </flux:text>
                </div>
                <livewire:portal.connect/>
            @elseif ($step === AppPreferences::STEP_NOTIFICATIONS)
                {{-- 3.5 Permission-Priming für Push (erklärt das Warum vor dem OS-Dialog). --}}
                <div class="flex flex-col items-center gap-4 text-center">
                    <span class="relative flex size-16 items-center justify-center rounded-tile bg-brand-500/10 text-brand-600 dark:text-brand-400">
                        <span class="absolute -inset-3 rounded-full bg-brand-500/20 blur-2xl" aria-hidden="true"></span>
                        <flux:icon name="bell-alert" class="relative size-8"/>
                    </span>
                    <div>
                        <flux:heading size="xl" level="1">{{ __('Nichts mehr verpassen') }}</flux:heading>
                        <flux:text class="mx-auto mt-2 max-w-xs">
                            {{ __('Erlaube Benachrichtigungen, damit wir dich vor Terminen deiner Meetups erinnern. Du entscheidest, kein Spam.') }}
                        </flux:text>
                    </div>
                </div>
            @elseif ($step === AppPreferences::STEP_DONE)
                <div class="flex flex-col items-center gap-4 text-center">
                    <span class="relative flex size-20 items-center justify-center rounded-full bg-brand-500/10 text-brand-600 dark:text-brand-400">
                        <span class="absolute -inset-4 rounded-full bg-brand-500/25 blur-2xl" aria-hidden="true"></span>
                        <flux:icon name="check-badge" class="relative size-10"/>
                    </span>
                    <div>
                        <flux:heading size="xl" level="1">{{ __('Alles bereit!') }}</flux:heading>
                        <flux:text class="mx-auto mt-2 max-w-xs">
                            {{ __('Viel Spaß beim Entdecken der Bitcoin-Community.') }}
                        </flux:text>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Aktions-Leiste je Schritt (Phase 3.1/3.4/3.5). --}}
    <div class="flex flex-col gap-2 p-6 pt-0">
        @if ($step === AppPreferences::STEP_WELCOME)
            <flux:button wire:click="next" variant="primary" class="w-full cursor-pointer" x-on:click="$haptic('light')">
                {{ __('Los geht’s') }}
            </flux:button>
        @elseif ($step === AppPreferences::STEP_LANGUAGE || $step === AppPreferences::STEP_REGION)
            <flux:button wire:click="next" variant="primary" class="w-full cursor-pointer" x-on:click="$haptic('light')">
                {{ __('Weiter') }}
            </flux:button>
        @elseif ($step === AppPreferences::STEP_PORTAL)
            @if ($this->portalConnected)
                <flux:button wire:click="next" variant="primary" class="w-full cursor-pointer" x-on:click="$haptic('light')">
                    {{ __('Weiter') }}
                </flux:button>
            @else
                <flux:button wire:click="skip" variant="ghost" class="w-full cursor-pointer">
                    {{ __('Ohne Konto fortfahren') }}
                </flux:button>
            @endif
        @elseif ($step === AppPreferences::STEP_NOTIFICATIONS)
            <flux:button wire:click="enableNotifications" variant="primary" icon="bell-alert" class="w-full cursor-pointer" x-on:click="$haptic('light')">
                {{ __('Benachrichtigungen erlauben') }}
            </flux:button>
            <flux:button wire:click="skip" variant="ghost" class="w-full cursor-pointer">
                {{ __('Nicht jetzt') }}
            </flux:button>
        @elseif ($step === AppPreferences::STEP_DONE)
            <flux:button wire:click="finish" variant="primary" class="w-full cursor-pointer" x-on:click="$haptic('light')">
                {{ __('App starten') }}
            </flux:button>
        @endif
    </div>
</div>
