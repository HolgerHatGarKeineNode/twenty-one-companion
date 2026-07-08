<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    {{-- Start-Weiche: In den Chat, wenn dort eingeloggt, sonst in die Meetups.
         Der Chat-Login lebt auf Mobile ausschließlich client-seitig — welshman
         persistiert den pubkey per JSON.stringify in localStorage['pubkey']
         (siehe nostr-chat js/session.ts). Der Server kann das nicht sehen, daher
         entscheidet dieses Mini-Dokument im <head>, bevor etwas gerendert wird. --}}
    <script>
        (function () {
            var target = @js(route('meetups'));
            try {
                var pk = JSON.parse(localStorage.getItem('pubkey'));
                if (typeof pk === 'string' && pk.length > 0) {
                    target = @js(route('chat.spaces'));
                }
            } catch (e) { /* localStorage nicht lesbar → Meetups */ }
            window.location.replace(target);
        })();
    </script>
</head>
<body style="margin:0;background:#09090b"></body>
</html>
