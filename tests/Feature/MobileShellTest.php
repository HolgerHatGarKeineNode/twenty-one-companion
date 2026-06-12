<?php

use App\Models\User;

it('shows the start screen with the bottom navigation', function () {
    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertSee('Willkommen bei EINUNDZWANZIG')
        ->assertSee('Meetups')
        ->assertSee('Termine')
        ->assertSee('Einstellungen')
        ->assertSee(route('meetups'))
        ->assertSee(route('events'));
});

it('redirects guests from settings to the login page', function () {
    $response = $this->get(route('settings'));

    $response->assertRedirect(route('login'));
});

it('redirects authenticated users from settings to the profile page', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('settings'));

    $response->assertRedirect(route('profile.edit'));
});
