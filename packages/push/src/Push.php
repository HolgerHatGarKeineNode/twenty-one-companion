<?php

namespace Einundzwanzig\Push;

use Throwable;

class Push
{
    /**
     * Löst EINEN sofortigen Poll-Lauf aus, statt auf den 15-Minuten-Takt zu
     * warten — der Diagnose-Trigger hinter der gegateten Debug-Route. Zustand
     * kommt aus {@see sync()}, nicht aus den Parametern.
     *
     * Gibt true zurück, wenn der Job eingeplant wurde — NICHT, ob er erfolgreich
     * war. Das Ergebnis steht nur im logcat: `adb logcat -s PushPoll`.
     */
    public function pollNow(int $delaySeconds = 30): bool
    {
        $result = $this->call('Push.PollNow', ['delaySeconds' => $delaySeconds]);

        return ($result['scheduled'] ?? false) === true;
    }

    /**
     * Legt den Client-Zustand nativ ab und gleicht das periodische Polling damit
     * ab. Leerer Zustand (ausgeloggt, Schalter aus, keine Räume) → Polling wird
     * gestoppt. Idempotent, läuft bei jedem App-Start.
     *
     * @param  array{pubkey?: string, relay?: string, rooms?: array<int, string>, session?: mixed}  $state
     */
    public function sync(array $state = []): bool
    {
        try {
            $json = json_encode($state, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }

        return ($this->call('Push.Sync', ['state' => $json])['scheduled'] ?? false) === true;
    }

    /**
     * Zeigt den Android-13+-Dialog für POST_NOTIFICATIONS.
     *
     * Der Dialog ist asynchron — das Ergebnis liefert erst ein späterer
     * {@see notificationPermissionGranted()}. Rückgabe hier heißt nur: war die
     * Berechtigung schon da (true) oder wurde der Dialog ausgelöst (false).
     */
    public function requestNotificationPermission(): bool
    {
        return ($this->call('Push.RequestNotificationPermission')['granted'] ?? false) === true;
    }

    /** Ob POST_NOTIFICATIONS aktuell gewährt ist. */
    public function notificationPermissionGranted(): bool
    {
        return ($this->call('Push.NotificationPermissionStatus')['granted'] ?? false) === true;
    }

    /**
     * @return array<string, mixed>
     */
    private function call(string $method, array $params = []): array
    {
        if (! function_exists('nativephp_call')) {
            return [];
        }

        try {
            $raw = nativephp_call($method, json_encode($params, JSON_THROW_ON_ERROR) ?: '{}');
        } catch (Throwable) {
            return [];
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
