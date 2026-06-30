<?php

namespace Einundzwanzig\Calendar;

use Illuminate\Support\ServiceProvider;

class CalendarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Calendar::class, fn (): Calendar => new Calendar);
    }
}
