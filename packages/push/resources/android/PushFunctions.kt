package com.einundzwanzig.push

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.fragment.app.FragmentActivity
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequest
import androidx.work.PeriodicWorkRequest
import androidx.work.WorkManager
import com.nativephp.mobile.bridge.BridgeFunction
import org.json.JSONObject
import java.util.concurrent.TimeUnit

/**
 * Namespace: "Push.*"
 *
 * Chat-Benachrichtigungen ohne Google und ohne Zweit-App: [Sync] nimmt den
 * Client-Zustand entgegen und hält das periodische Polling am Leben,
 * [RelayPollWorker] macht die Arbeit. Siehe plans/PUSH-NOTIFICATIONS.md.
 */
object PushFunctions {

    const val DEFAULT_SIGNER = "com.greenart7c3.nostrsigner"

    private const val UNIQUE_PERIODIC = "push-relay-periodic"

    /**
     * Löst EINEN Poll-Lauf sofort aus, statt auf den 15-Minuten-Takt zu warten —
     * der einzige Weg, den Worker am Gerät gezielt zu beobachten
     * (`adb logcat -s PushPoll`). Hängt an der gegateten Debug-Route
     * (`PUSH_DEBUG=true`), nicht an Produktionscode.
     *
     * Parameters: delaySeconds (optional, Default 30) — Zeit, um die App vorher
     * in den Hintergrund zu schicken. Pubkey/Relay/Räume/Session kommen aus
     * [Sync], nicht aus den Parametern.
     */
    class PollNow(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val delaySeconds = (parameters["delaySeconds"] as? Number)?.toLong() ?: 30L

            val request = OneTimeWorkRequest.Builder(RelayPollWorker::class.java)
                .setInitialDelay(delaySeconds, TimeUnit.SECONDS)
                .setConstraints(
                    Constraints.Builder()
                        .setRequiredNetworkType(NetworkType.CONNECTED)
                        .build(),
                )
                .build()

            // Ohne TAG_PERIODIC: ein Fehllauf soll hier einmal ein Ergebnis loggen
            // und ruhen, nicht in den Backoff-Retry des echten Takts laufen.
            WorkManager.getInstance(context).enqueueUniqueWork(
                "push-poll-now",
                ExistingWorkPolicy.REPLACE,
                request,
            )

            Log.i(RelayPollWorker.TAG, "RELAY: Lauf geplant in ${delaySeconds}s")

            return mapOf("scheduled" to true, "delaySeconds" to delaySeconds)
        }
    }

    /**
     * Legt den Client-Zustand ab und gleicht das periodische Polling damit ab
     * (Flotillas syncState-Muster, AndroidPushFallbackPlugin.kt:37-51).
     *
     * Der Zustand MUSS über SharedPreferences laufen, nicht über WorkManager-`Data`:
     * er enthält den NIP-46-Signing-Key und wächst mit der Raumliste, `Data` ist
     * auf 10 KB gedeckelt und landet in WorkManagers eigener DB.
     *
     * `activeSince` wird HIER gestempelt, nicht vom Client: der Aufruf passiert beim
     * App-Start, also genau dann, wenn der Nutzer den Chat gesehen hat. Ohne diesen
     * Boden würde der erste Lauf die letzten 20 Nachrichten als Notification nachholen.
     *
     * Parameters:
     *   - state: JSON-String {pubkey, relay, rooms:[h], session?, signerPackage?}
     *
     * Kein Pubkey oder keine Räume → Zustand löschen und Polling abbestellen.
     * 15 Minuten ist WorkManagers Minimum, kürzer geht nicht.
     */
    class Sync(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val prefs = context.getSharedPreferences(
                RelayPollWorker.PREFS_NAME,
                Context.MODE_PRIVATE,
            )
            val state = runCatching {
                JSONObject((parameters["state"] as? String).orEmpty())
            }.getOrNull()

            val pubkey = state?.optString("pubkey").orEmpty()
            val rooms = state?.optJSONArray("rooms")?.length() ?: 0

            if (pubkey.isEmpty() || rooms == 0) {
                prefs.edit().remove(RelayPollWorker.KEY_STATE).apply()
                WorkManager.getInstance(context).cancelUniqueWork(UNIQUE_PERIODIC)
                Log.i(RelayPollWorker.TAG, "RELAY: periodic work cancelled (kein Zustand)")

                return mapOf("scheduled" to false)
            }

            state!!.put("activeSince", System.currentTimeMillis() / 1000)
            prefs.edit().putString(RelayPollWorker.KEY_STATE, state.toString()).apply()

            val request = PeriodicWorkRequest.Builder(
                RelayPollWorker::class.java,
                15,
                TimeUnit.MINUTES,
            )
                .setConstraints(
                    Constraints.Builder()
                        .setRequiredNetworkType(NetworkType.CONNECTED)
                        .build(),
                )
                // Fehlläufe (Signer widerrufen, Telefon aus) dünnen sich selbst aus:
                // 15 → 30 → 60 → 120 → 240 → 300 min (5h-Deckel von WorkManager)
                // statt stur 96× am Tag. Greift nur, wenn der Worker `retry()`
                // zurückgibt; bei Erfolg setzt WorkManager den Zähler zurück.
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 15, TimeUnit.MINUTES)
                .addTag(RelayPollWorker.TAG_PERIODIC)
                .build()

            // CANCEL_AND_REENQUEUE statt UPDATE: der App-Start soll einen laufenden
            // Backoff LÖSCHEN. Sonst wartet jemand, dessen Bunker-Telefon nachts aus
            // war, morgens bis zu 5 h auf Notifications, obwohl längst alles wieder
            // geht — der Zähler steht dann auf Versuch 6. Die App offen zu haben
            // heisst: der Nutzer ist da und hat womöglich genau das repariert.
            //
            // Dass dabei auch der 15-Minuten-Takt neu beginnt, ist kein Verlust: wer
            // die App gerade offen hat, sieht den Chat direkt und braucht kein Push.
            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                UNIQUE_PERIODIC,
                ExistingPeriodicWorkPolicy.CANCEL_AND_REENQUEUE,
                request,
            )

            Log.i(RelayPollWorker.TAG, "RELAY: state synced ($rooms Räume), periodic work enqueued (15 min)")

            return mapOf("scheduled" to true, "intervalMinutes" to 15, "rooms" to rooms)
        }
    }

    /**
     * Fragt POST_NOTIFICATIONS an (Android 13+ Runtime-Permission).
     *
     * Braucht eine Activity, daher FragmentActivity statt Context — NativePHP
     * injiziert beides. Der Dialog ist asynchron; das Ergebnis holt man danach
     * mit [NotificationPermissionStatus] ab, statt hier auf einen Callback zu
     * warten (die Bridge ist synchron).
     */
    class RequestNotificationPermission(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val granted = activity.checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) ==
                PackageManager.PERMISSION_GRANTED

            if (granted) {
                return mapOf("granted" to true, "requested" to false)
            }

            // requestPermissions muss auf dem UI-Thread laufen.
            Handler(Looper.getMainLooper()).post {
                activity.requestPermissions(arrayOf(Manifest.permission.POST_NOTIFICATIONS), 8121)
            }

            return mapOf("granted" to false, "requested" to true)
        }
    }

    /** Ob POST_NOTIFICATIONS aktuell gewährt ist. */
    class NotificationPermissionStatus(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            mapOf(
                "granted" to (
                    context.checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) ==
                        PackageManager.PERMISSION_GRANTED
                    ),
            )
    }
}
