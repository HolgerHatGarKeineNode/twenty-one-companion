<?php

/**
 * `/` forwards to Start again — server-side, and that is a reversal worth recording.
 *
 * The test used to hang on a server 302 to the meetups (27169bf) and went red when the launch
 * switch arrived (592e36c): the chat login lives on mobile only client-side
 * (`localStorage['pubkey']`), so `launch.blade.php` decided between chat and meetups in the
 * browser — a page whose whole content was a redirect.
 *
 * Concept C removes the question instead of answering it faster: Start renders for a guest and
 * a member alike and decides the difference in its own island with a skeleton (D4). There is
 * nothing left for the client to route, so the server may do it — and does.
 */
it('forwards the root to Start', function () {
    $this->get(route('home'))->assertRedirect(route('group.start'));
});
