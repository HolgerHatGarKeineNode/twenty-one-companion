package com.einundzwanzig.push

import android.Manifest
import android.app.ActivityManager
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.util.Log
import androidx.work.Worker
import androidx.work.WorkerParameters
import com.einundzwanzig.ambersigner.AmberSignerFunctions
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.Response
import okhttp3.WebSocket
import okhttp3.WebSocketListener
import org.json.JSONArray
import org.json.JSONObject
import java.util.UUID
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit

/**
 * Der Poll-Lauf (plans/PUSH-NOTIFICATIONS.md): kompletter Lesepfad im Hintergrund.
 *
 * Öffnet einen Socket zum Gruppen-Relay, beantwortet dessen NIP-42-Challenge mit
 * einer Signatur (headless) und holt die Nachrichten aus den Räumen des Nutzers
 * seit dem Cursor.
 *
 * Signiert wird nach der Login-Methode der Session: Amber per ContentResolver
 * (NIP-55, der Normalfall auf dem Gerät), Bunker per NIP-46 oder lokal mit dem
 * Session-Key (NIP-01). Ohne den NIP-46-Pfad bekämen Bunker-Nutzer still gar
 * keine Notifications.
 *
 * Zustand (pubkey, Relay, Räume, Session) kommt aus SharedPreferences, abgelegt
 * von [PushFunctions.Sync] — der Client ist die einzige Quelle dafür.
 *
 * Ablauf nach NIP-01/NIP-42, portiert von Flotilla (MIT),
 * AndroidPushFallbackWorker.kt:557-700:
 *   onOpen → REQ → Relay: AUTH <challenge> → kind 22242 signieren → AUTH →
 *   OK(accepted) → REQ erneut → EVENT* → EOSE → CLOSE
 *
 * adb logcat -s PushPoll
 */
class RelayPollWorker(context: Context, params: WorkerParameters) :
    Worker(context, params) {

    /** Trägt beide Sockets: Relay und (bei NIP-46) den Bunker-Relay. */
    private val client = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(TIMEOUT_SECONDS, TimeUnit.SECONDS)
        .build()

    companion object {
        const val TAG = "PushPoll"

        /**
         * Markiert den periodischen Lauf. Der Diagnose-Trigger (`Push.PollNow`)
         * teilt sich diesen Worker, soll aber nicht in den Backoff laufen — er
         * ist ein einmaliger Trigger.
         */
        const val TAG_PERIODIC = "push-periodic"

        /** Eigene Prefs-Datei — NativePHPs Storage teilen wir uns bewusst nicht. */
        const val PREFS_NAME = "einundzwanzig_push"
        const val KEY_STATE = "push.state"

        /** Cursor je Relay: bis hierhin wurde bereits benachrichtigt. */
        private const val CURSOR_PREFIX = "push.cursor."

        private const val KIND_RELAY_AUTH = 22242
        private const val KIND_NIP46_RPC = 24133
        private const val KIND_GROUP_CHAT = 9
        private const val TIMEOUT_SECONDS = 30L

        /**
         * Wartezeit auf EINEN Signer-Relay. Kurz, weil der Signer im Normalfall
         * ohne Nutzer antwortet: die beim Connect gewährten Perms decken
         * `sign_event:22242`, gemessene Roundtrips sind 1,3s und 2,3s. Schweigt
         * ein Relay länger, ist nicht „der Nutzer tippt langsam" die Erklärung,
         * sondern „hier kommt nichts durch" — dann ist der nächste Relay dran.
         * Deshalb pro Relay, nicht als Gesamt-Budget: welshman persistiert genau
         * darum mehrere (`broker.params.relays`).
         *
         * 8s = 3,5× der gemessenen Antwortzeit. Bewusst knapp: ein unerreichbarer
         * Signer (widerrufen, Telefon aus) schweigt für IMMER, und dieser Lauf
         * wiederholt sich alle 15 Minuten — jede Sekunde Timeout ist dann Funk
         * ohne Gegenwert. Verliert ein schlafendes Telefon dadurch einen Lauf,
         * kommt die Nachricht 15 Minuten später statt gar nicht.
         */
        private const val NIP46_RELAY_TIMEOUT_MILLIS = 8_000L

        /**
         * Deckel über ALLE Signer-Relays. Ohne ihn sprengt die Summe den
         * Socket-Timeout: mit 3 Relays à 15s (45s > 30s) gab der äußere Latch bei
         * 30s auf, während noch Relays offen waren, und der dritte bekam nur noch
         * „executor rejected" — `doWork()` hatte den Dispatcher längst abgeräumt.
         * Am Gerät beobachtet.
         *
         * 24s = die üblichen 3 Relays à 8s. Zusammen mit einem realen Connect
         * (~1s, gemessen) bleibt das unter TIMEOUT_SECONDS; erst eine Session mit
         * mehr Relays läuft in den Deckel, und dann ist Abschneiden richtig.
         */
        private const val NIP46_BUDGET_MILLIS = 24_000L
        private const val CHANNEL_ID = "einundzwanzig_chat"
        private const val DEEPLINK_SCHEME = "einundzwanzig"

        /**
         * Erlaubte Form einer NIP-29-Raum-ID. Bewusst ohne `/ ? # %` — genau die
         * Zeichen, mit denen sich aus dem Deeplink ein anderer Pfad bauen liesse
         * (siehe [PollListener.groupTag]). Echte IDs sehen aus wie
         * `eegreyplugough8`; NIP-29 schreibt nichts vor, deshalb sind Punkt,
         * Tilde, Unterstrich und Bindestrich mit erlaubt.
         */
        private val GROUP_ID = Regex("^[A-Za-z0-9._~-]{1,64}$")

        /** Toleranz für Uhren-Drift zwischen Relay und Gerät beim Cursor. */
        private const val CLOCK_SKEW_SECONDS = 60L
        private const val DEFAULT_SIGNER = "com.greenart7c3.nostrsigner"
    }

    override fun doWork(): Result {
        // Vordergrund → gar nichts tun (Flotilla, Worker.kt:77-79). Wer die App
        // offen hat, braucht keine Notification, und der Lauf kostet Funk.
        //
        // Wichtig: Der Cursor bleibt dabei stehen, es geht also nichts verloren —
        // der nächste Lauf im Hintergrund holt die Nachrichten nach. Das trifft
        // auch den Lauf direkt nach dem App-Start (der Sync stösst ihn an, während
        // die App noch offen ist); der fand wegen `activeSince` ohnehin nie etwas.
        if (isAppInForeground()) {
            Log.i(TAG, "RELAY: App im Vordergrund — Lauf übersprungen")

            return Result.success()
        }

        val prefs = applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        val raw = prefs.getString(KEY_STATE, "").orEmpty()

        if (raw.isEmpty()) {
            Log.i(TAG, "RELAY: kein Zustand — Lauf verworfen")
            return Result.success()
        }

        val state = runCatching { JSONObject(raw) }.getOrNull()
            ?: return Result.failure()

        val pubkey = state.optString("pubkey")
        val relay = state.optString("relay")
        val rooms = state.optJSONArray("rooms") ?: JSONArray()
        val signerPackage = state.optString("signerPackage").takeIf { it.isNotEmpty() }
            ?: DEFAULT_SIGNER

        if (pubkey.isEmpty() || rooms.length() == 0) {
            Log.w(TAG, "RELAY: MISCONFIGURED — pubkey oder Räume fehlen")
            return Result.failure()
        }

        // Der Relay kommt aus dem Client (aktiver Space) und wandert in einen
        // Socket — hier ist die Trust-Grenze, wie in Flotilla (Worker.kt:142-160).
        if (!relay.startsWith("wss://") && !relay.startsWith("ws://")) {
            Log.w(TAG, "RELAY: MISCONFIGURED — ungültige Relay-URL '$relay'")
            return Result.failure()
        }

        val cursorKey = CURSOR_PREFIX + relay
        val since = maxOf(prefs.getLong(cursorKey, 0L), state.optLong("activeSince", 0L))

        Log.i(
            TAG,
            "RELAY: connecting to $relay as ${pubkey.take(8)}… " +
                "(${rooms.length()} Räume, since=$since)",
        )

        val latch = CountDownLatch(1)
        val listener = PollListener(
            pubkey,
            signerPackage,
            state.optJSONObject("session"),
            relay,
            rooms,
            state.optJSONObject("names") ?: JSONObject(),
            since,
            latch,
        )

        val socket = client.newWebSocket(Request.Builder().url(relay).build(), listener)

        if (!latch.await(TIMEOUT_SECONDS, TimeUnit.SECONDS)) {
            Log.w(TAG, "RELAY: TIMEOUT after ${TIMEOUT_SECONDS}s — authed=${listener.authed} events=${listener.events}")
        }

        // Socket UND Verbindungspool zumachen — sonst bleibt die TCP-Verbindung
        // offen und hält das Funkmodul wach, obwohl der Lauf vorbei ist.
        //
        // Das nostr-`CLOSE` bei EOSE beendet nur die SUBSCRIPTION, nicht den
        // Socket. Und `dispatcher.shutdown()` allein reicht nicht: der
        // Verbindungspool ist davon unberührt und hält die Verbindung (plus
        // Cleanup-Thread) bis zu 5 Minuten leerlaufend am Leben. Da pro Lauf ein
        // eigener OkHttpClient entsteht (WorkManager baut je Lauf einen neuen
        // Worker), summiert sich das über die Läufe im selben Prozess.
        socket.close(1000, null)
        client.dispatcher.executorService.shutdown()
        client.connectionPool.evictAll()

        // Erst NACH dem Lauf schreiben: bricht der Socket ab, bleibt der alte
        // Cursor stehen und der nächste Lauf holt die Lücke nach.
        if (listener.lastCursor > prefs.getLong(cursorKey, 0L)) {
            prefs.edit().putLong(cursorKey, listener.lastCursor).apply()
            Log.i(TAG, "RELAY: cursor → ${listener.lastCursor}")
        }

        // Auth gescheitert (Signer widerrufen, Telefon aus, Relay down) → `retry()`
        // statt `success()`. Ein solcher Lauf kostet bis zu 24s Funk und wiederholt
        // sich sonst STUR alle 15 Minuten, für immer — 96× am Tag ohne jede Aussicht
        // auf Erfolg.
        //
        // Den Backoff macht WorkManager, kein eigener Zähler: `setBackoffCriteria`
        // in [PushFunctions.Sync] gibt 15 min exponentiell, gedeckelt bei 5 h
        // (WorkRequest.MAX_BACKOFF_MILLIS) → 15, 30, 60, 120, 240, 300, 300 … statt
        // 15, 15, 15 … Und `WorkSpec.calculateNextRunTime()` prüft `isBackedOff`
        // VOR `isPeriodic` — der Backoff ersetzt den Takt, er kommt nicht obendrauf.
        // Kommt der Signer zurück, setzt `resetPeriodicAndResolve()` den Zähler bei
        // Erfolg selbst zurück (beides im Bytecode von work-runtime 2.9.1 geprüft).
        //
        // Nur für den periodischen Lauf: der Diagnose-Trigger würde sonst im
        // Fehlerfall ewig weiterversuchen, statt einmal ein Ergebnis zu loggen.
        if (!listener.authed && tags.contains(TAG_PERIODIC)) {
            Log.w(TAG, "RELAY: Auth gescheitert — Backoff (Versuch ${runAttemptCount + 1})")

            return Result.retry()
        }

        return Result.success()
    }

    /**
     * Ob unser eigener Prozess gerade im Vordergrund ist (Flotilla,
     * Worker.kt:126-131).
     */
    private fun isAppInForeground(): Boolean {
        val manager = applicationContext.getSystemService(ActivityManager::class.java)
            ?: return false
        val processes = manager.runningAppProcesses ?: return false

        return processes.any {
            it.processName == applicationContext.packageName &&
                it.importance == ActivityManager.RunningAppProcessInfo.IMPORTANCE_FOREGROUND
        }
    }

    private inner class PollListener(
        private val pubkey: String,
        private val signerPackage: String,
        private val session: JSONObject?,
        private val relayUrl: String,
        private val rooms: JSONArray,
        /** Raum-ID → Anzeigename, aus dem letzten Sync. Kann leer sein. */
        private val roomNames: JSONObject,
        private val since: Long,
        private val latch: CountDownLatch,
    ) : WebSocketListener() {

        private val subId = UUID.randomUUID().toString().replace("-", "")
        private var authEventId = ""
        private var authSent = false
        private var done = false

        var authed = false
            private set
        var events = 0
            private set

        /** Höchstes `created_at`, zu dem benachrichtigt wurde. */
        var lastCursor = 0L
            private set

        override fun onOpen(webSocket: WebSocket, response: Response) {
            Log.i(TAG, "RELAY: socket open")
            sendReq(webSocket)
        }

        override fun onMessage(webSocket: WebSocket, text: String) {
            try {
                val message = JSONArray(text)
                when (message.optString(0, "")) {
                    "AUTH" -> {
                        if (!authSent) {
                            authSent = true
                            handleAuthChallenge(webSocket, message.optString(1, ""))
                        }
                    }

                    "OK" -> {
                        val okId = message.optString(1, "")
                        val accepted = message.optBoolean(2, false)
                        if (okId == authEventId) {
                            if (accepted) {
                                authed = true
                                Log.i(TAG, "RELAY: AUTH ACCEPTED — re-sending REQ")
                                sendReq(webSocket)
                            } else {
                                Log.w(TAG, "RELAY: AUTH REJECTED — ${message.optString(3, "")}")
                                finish()
                            }
                        }
                    }

                    "EVENT" -> {
                        val event = message.optJSONObject(2) ?: return
                        events++

                        // Cursor gegen die Wanduhr deckeln: `created_at` kommt roh vom
                        // Relay und wird persistiert. Ein Event aus dem Jahr 2100 hätte
                        // den Cursor für 74 Jahre in die Zukunft geschoben — der Nutzer
                        // bekäme NIE wieder eine Notification, und weder der Schalter
                        // noch ein Logout räumen `push.cursor.*` weg.
                        val createdAt = event.optLong("created_at", 0L)
                        if (createdAt <= System.currentTimeMillis() / 1000 + CLOCK_SKEW_SECONDS) {
                            lastCursor = maxOf(lastCursor, createdAt)
                        } else {
                            Log.w(TAG, "RELAY: created_at liegt in der Zukunft — Cursor nicht bewegt")
                        }

                        // Kein `content` ins Log: das ist der Klartext einer privaten
                        // Nachricht, und logcat liest jede App mit READ_LOGS sowie jeder
                        // Bugreport mit. Genau deshalb ist auch push/sync ein POST (§5).
                        Log.i(TAG, "RELAY: EVENT #$events kind=${event.optInt("kind")}")

                        // Eigene Nachrichten: Cursor ja (die sind gesehen), Notification
                        // nein — sonst meldet das Handy jede Nachricht, die man selbst
                        // gerade geschrieben hat. Der `#h`-REQ kann sie nicht ausfiltern,
                        // ein `authors`-Filter kennt nur Positiv-Listen.
                        if (event.optString("pubkey") == pubkey) {
                            Log.i(TAG, "RELAY: eigene Nachricht — keine Notification")

                            return
                        }

                        postNotification(event)
                    }

                    "CLOSED" -> {
                        // Der Pre-Auth-REQ wird vom Relay mit `CLOSED auth-required`
                        // beantwortet — das ist der NORMALE Weg zur Challenge, kein
                        // Abbruch. Nur ein CLOSED NACH erfolgreicher Auth beendet.
                        val reason = message.optString(2, "")
                        if (authed) {
                            Log.w(TAG, "RELAY: CLOSED — $reason")
                            finish()
                        } else {
                            Log.i(TAG, "RELAY: CLOSED vor Auth (erwartet) — $reason")
                        }
                    }

                    "EOSE" -> {
                        Log.i(TAG, "RELAY: EOSE — authed=$authed, $events Event(e) empfangen")
                        webSocket.send(JSONArray().apply { put("CLOSE"); put(subId) }.toString())
                        finish()
                    }

                    "NOTICE" -> Log.w(TAG, "RELAY: NOTICE — ${message.optString(1, "")}")
                }
            } catch (e: Exception) {
                Log.e(TAG, "RELAY: parse error on: ${text.take(120)}", e)
                finish()
            }
        }

        override fun onFailure(webSocket: WebSocket, t: Throwable, response: Response?) {
            Log.e(TAG, "RELAY: FAILURE — ${t.javaClass.simpleName}: ${t.message}")
            finish()
        }

        override fun onClosed(webSocket: WebSocket, code: Int, reason: String) {
            Log.i(TAG, "RELAY: closed ($code $reason)")
            finish()
        }

        private fun sendReq(webSocket: WebSocket) {
            // `#h` = die Räume des Nutzers. Die Gruppen-ACL macht zooid zwar
            // serverseitig anhand des authed pubkey, aber gemeldet werden soll
            // nur, wo der Nutzer auch drin ist.
            //
            // ponytail: `since + 1` ist exklusiv — Nachrichten in derselben
            // Sekunde wie die zuletzt gemeldete fallen unter den Tisch. Upgrade:
            // persistente seen-Menge wie Flotillas Dedup, falls das je auffällt.
            val req = JSONArray().apply {
                put("REQ")
                put(subId)
                put(JSONObject().apply {
                    put("kinds", JSONArray().apply { put(KIND_GROUP_CHAT) })
                    put("#h", rooms)
                    if (since > 0) {
                        put("since", since + 1)
                    }
                    put("limit", 20)
                })
            }
            Log.i(TAG, "RELAY: REQ ${req.optJSONObject(2)}")
            webSocket.send(req.toString())
        }

        private fun handleAuthChallenge(webSocket: WebSocket, challenge: String) {
            if (challenge.isEmpty()) {
                Log.w(TAG, "RELAY: empty AUTH challenge")
                return
            }

            // welshman-Methode (localStorage['sessions'][pubkey], §5): `nip46` ist
            // der Bunker, `nip01` ein lokaler Key. `nip07` ist auf dem Gerät unser
            // Amber-Login (js/session.ts:71) — der ist auch der Fallback, wenn keine
            // Session mitkam.
            val method = session?.optString("method").orEmpty()

            Log.i(TAG, "RELAY: AUTH challenge received, signing via ${method.ifEmpty { "nip55" }}…")

            val unsigned = JSONObject().apply {
                put("kind", KIND_RELAY_AUTH)
                put("pubkey", pubkey)
                put("created_at", System.currentTimeMillis() / 1000)
                put("content", "")
                put("id", "")
                put("sig", "")
                put(
                    "tags",
                    JSONArray().apply {
                        put(JSONArray().apply { put("relay"); put(relayUrl) })
                        put(JSONArray().apply { put("challenge"); put(challenge) })
                    },
                )
            }.toString()

            val signed = when (method) {
                "nip46" -> signViaNip46(unsigned)
                "nip01" -> NostrCrypto.signWithNip01Secret(
                    session?.optString("secret").orEmpty(),
                    unsigned,
                    pubkey,
                )

                else -> signViaAmber(unsigned)
            }

            if (signed.isEmpty()) {
                finish()
                return
            }

            val event = JSONObject(signed)
            authEventId = event.optString("id", "")
            Log.i(TAG, "RELAY: sending AUTH (event ${authEventId.take(8)}…)")
            webSocket.send(JSONArray().apply { put("AUTH"); put(event) }.toString())
        }

        /** NIP-55: Amber signiert headless über den ContentResolver (§3). */
        private fun signViaAmber(unsigned: String): String {
            val result = AmberSignerFunctions.SignEvent(applicationContext).execute(
                mapOf(
                    "event" to unsigned,
                    "currentUser" to pubkey,
                    "amberPackage" to signerPackage,
                ),
            )

            val signed = result["event"] as? String
            if (signed.isNullOrEmpty()) {
                Log.e(
                    TAG,
                    "RELAY: signing FAILED — authorized=${result["authorized"]} " +
                        "rejected=${result["rejected"]} error=${result["error"]}",
                )
                return ""
            }

            return signed
        }

        /**
         * NIP-46: den Bunker über seine Relays um die Signatur bitten. Portiert von
         * Flotilla (MIT), AndroidPushFallbackWorker.kt:715-840.
         *
         * Der Kontoschlüssel liegt beim Signer; die Session hält nur den
         * Client-Schlüssel (`secret`), mit dem wir die RPC-Hülle selbst signieren
         * und ihren Inhalt an den Bunker verschlüsseln.
         *
         * Erster antwortender Relay gewinnt; die Relays teilen sich
         * [NIP46_BUDGET_MILLIS]. Das blockiert den Reader-Thread des
         * Relay-Sockets so lange — das tut der Amber-Pfad genauso, und ein
         * Hintergrundlauf hat nichts anderes zu tun.
         */
        private fun signViaNip46(unsigned: String): String {
            val handler = session?.optJSONObject("handler")
            val clientSecret = session?.optString("secret").orEmpty()
            val signerPubkey = handler?.optString("pubkey").orEmpty()
            val relays = handler?.optJSONArray("relays") ?: JSONArray()
            val clientPubkey = NostrCrypto.deriveXOnlyPubkey(clientSecret)

            if (signerPubkey.isEmpty() || relays.length() == 0 || clientPubkey.isEmpty()) {
                Log.e(TAG, "RELAY: NIP-46 session incomplete — kein Bunker erreichbar")
                return ""
            }

            val deadline = System.currentTimeMillis() + NIP46_BUDGET_MILLIS

            for (i in 0 until relays.length()) {
                val signerRelay = relays.optString(i, "").trim()
                if (!signerRelay.startsWith("wss://") && !signerRelay.startsWith("ws://")) {
                    continue
                }

                // Jeder Relay bekommt seine eigene (kurze) Chance — der Fallback
                // existiert für den Fall „Relay tot/langsam", nicht „Signer weg".
                // Der Deckel greift nur, wenn die Session ungewöhnlich viele Relays
                // trägt; er schützt den äußeren Socket-Timeout.
                val remaining = deadline - System.currentTimeMillis()
                if (remaining <= 0) {
                    Log.w(TAG, "RELAY: NIP-46 Budget aufgebraucht — $signerRelay übersprungen")
                    continue
                }

                val signed = signViaNip46Relay(
                    signerRelay,
                    clientSecret,
                    clientPubkey,
                    signerPubkey,
                    unsigned,
                    minOf(NIP46_RELAY_TIMEOUT_MILLIS, remaining),
                )
                if (signed.isNotEmpty()) {
                    return signed
                }

                Log.w(TAG, "RELAY: NIP-46 keine Antwort von $signerRelay")
            }

            Log.e(TAG, "RELAY: NIP-46 signing FAILED — kein Relay hat geantwortet")

            return ""
        }

        private fun signViaNip46Relay(
            signerRelay: String,
            clientSecret: String,
            clientPubkey: String,
            signerPubkey: String,
            unsigned: String,
            timeoutMillis: Long,
        ): String {
            val conversationKey = NostrCrypto.nip44ConversationKey(clientSecret, signerPubkey)
            if (conversationKey.isEmpty()) {
                return ""
            }

            val requestId = UUID.randomUUID().toString().replace("-", "")
            val localLatch = CountDownLatch(1)
            val signedEvent = StringBuilder()

            val socket = client.newWebSocket(
                Request.Builder().url(signerRelay).build(),
                object : WebSocketListener() {
                    private var done = false

                    override fun onOpen(webSocket: WebSocket, response: Response) {
                        try {
                            val request = JSONObject().apply {
                                put("id", requestId)
                                put("method", "sign_event")
                                put("params", JSONArray().apply { put(unsigned) })
                            }

                            val envelope = JSONObject().apply {
                                put("kind", KIND_NIP46_RPC)
                                put("pubkey", clientPubkey)
                                put("created_at", System.currentTimeMillis() / 1000)
                                put("content", NostrCrypto.encryptNip44(request.toString(), conversationKey))
                                put("id", "")
                                put("sig", "")
                                put(
                                    "tags",
                                    JSONArray().apply {
                                        put(JSONArray().apply { put("p"); put(signerPubkey) })
                                    },
                                )
                            }

                            val signedEnvelope =
                                NostrCrypto.signWithNip01Secret(clientSecret, envelope.toString(), clientPubkey)
                            if (signedEnvelope.isEmpty()) {
                                finishLocal()
                                return
                            }

                            // `since` VOR dem Senden stempeln: die Antwort trägt die
                            // Uhr des Signers, ein späterer Boden würde sie ausfiltern.
                            val sentAt = System.currentTimeMillis() / 1000

                            webSocket.send(
                                JSONArray().apply { put("EVENT"); put(JSONObject(signedEnvelope)) }.toString(),
                            )
                            webSocket.send(
                                JSONArray().apply {
                                    put("REQ")
                                    put(requestId)
                                    put(
                                        JSONObject().apply {
                                            put("#p", JSONArray().apply { put(clientPubkey) })
                                            put("kinds", JSONArray().apply { put(KIND_NIP46_RPC) })
                                            put("since", sentAt)
                                            put("limit", 10)
                                        },
                                    )
                                }.toString(),
                            )
                        } catch (e: Exception) {
                            Log.e(TAG, "RELAY: NIP-46 request failed", e)
                            finishLocal()
                        }
                    }

                    override fun onMessage(webSocket: WebSocket, text: String) {
                        try {
                            val message = JSONArray(text)
                            if (message.optString(0, "") != "EVENT") {
                                return
                            }

                            val event = message.optJSONObject(2) ?: return
                            val decrypted =
                                NostrCrypto.decryptNip44(event.optString("content", ""), conversationKey)
                            if (decrypted.isEmpty()) {
                                return
                            }

                            val payload = JSONObject(decrypted)
                            if (payload.optString("id", "") != requestId) {
                                return
                            }

                            val result = payload.optString("result", "")
                            if (result.isEmpty()) {
                                Log.e(TAG, "RELAY: NIP-46 error — ${payload.optString("error", "?")}")
                                finishLocal()
                                return
                            }

                            signedEvent.append(result)
                            finishLocal()
                        } catch (e: Exception) {
                            Log.e(TAG, "RELAY: NIP-46 response error", e)
                        }
                    }

                    override fun onFailure(webSocket: WebSocket, t: Throwable, response: Response?) {
                        Log.w(TAG, "RELAY: NIP-46 socket failed — ${t.javaClass.simpleName}: ${t.message}")
                        finishLocal()
                    }

                    override fun onClosed(webSocket: WebSocket, code: Int, reason: String) = finishLocal()

                    private fun finishLocal() {
                        if (!done) {
                            done = true
                            localLatch.countDown()
                        }
                    }
                },
            )

            localLatch.await(timeoutMillis, TimeUnit.MILLISECONDS)
            socket.close(1000, null)

            return signedEvent.toString()
        }

        /**
         * Versuch 3: Notification anzeigen, Tap öffnet den Raum per Deeplink
         * (`einundzwanzig://rooms/{h}` → Route group.room).
         *
         * Framework-Notification statt NotificationCompat: minSdk ist 33, Channels
         * gibt es seit 26 — spart eine androidx-Abhängigkeit im Plugin.
         */
        private fun postNotification(event: JSONObject) {
            val context = applicationContext

            if (context.checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS)
                != PackageManager.PERMISSION_GRANTED
            ) {
                Log.w(TAG, "RELAY: POST_NOTIFICATIONS nicht gewährt — keine Notification")
                return
            }

            val manager = context.getSystemService(NotificationManager::class.java) ?: return

            if (manager.getNotificationChannel(CHANNEL_ID) == null) {
                manager.createNotificationChannel(
                    NotificationChannel(
                        CHANNEL_ID,
                        "Chat",
                        NotificationManager.IMPORTANCE_DEFAULT,
                    ),
                )
            }

            // Kein vertrauenswürdiger Raum → gar keine Notification. Lieber eine
            // Nachricht verpassen als einen Deeplink bauen, den das Relay diktiert.
            val h = groupTag(event) ?: run {
                Log.w(TAG, "RELAY: Event ohne gültige Raum-ID — keine Notification")

                return
            }

            val intent = Intent(Intent.ACTION_VIEW, Uri.parse("$DEEPLINK_SCHEME://rooms/$h")).apply {
                setPackage(context.packageName)
                flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
            }

            val pendingIntent = PendingIntent.getActivity(
                context,
                h.hashCode(),
                intent,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
            )

            // Titel = Raumname aus dem Sync; ohne ihn die Raum-ID, die immerhin
            // unterscheidbar ist. Ein Absendername steht hier bewusst NICHT: der
            // lebt in dessen kind 0, und das holt der Client über die
            // Indexer-Relays (purplepag.es & Co., welshman-Router), nicht über das
            // Gruppen-Relay, an dem dieser Worker hängt. Dafür eine zweite
            // Verbindung aufzumachen ist den Namen nicht wert.
            val title = roomNames.optString(h).takeIf { it.isNotEmpty() } ?: h
            val content = event.optString("content")

            val notification = Notification.Builder(context, CHANNEL_ID)
                .setSmallIcon(android.R.drawable.stat_notify_chat)
                .setContentTitle(title)
                .setContentText(content.take(120))
                .setStyle(Notification.BigTextStyle().bigText(content.take(400)))
                .setAutoCancel(true)
                .setContentIntent(pendingIntent)
                .build()

            manager.notify(event.optString("id").hashCode(), notification)
            Log.i(TAG, "RELAY: Notification gepostet (Raum $h)")
        }

        /**
         * Die Raum-ID des Events — oder null, wenn ihr nicht zu trauen ist.
         *
         * SICHERHEITSGRENZE. Der Wert kommt roh vom Relay und landete früher per
         * String-Konkatenation im Deeplink (`einundzwanzig://rooms/$h`). Ein `h`
         * wie `../auth?token=<fremdes Token>` wird daraus zu `/rooms/../auth?…`,
         * der WebView kanonisiert die Dot-Segmente weg (RFC 3986) und trifft
         * `Route::get('auth')` → `storeToken()`: ein Tap auf die Notification
         * hätte die Portal-Session gegen das Konto des Angreifers getauscht.
         *
         * Zwei Prüfungen, weil eine nicht reicht:
         *
         * 1. **Form.** Ohne `/ ? # %` ist der Angriff konstruktiv unmöglich —
         *    unabhängig davon, was ein Relay schickt. Prozent-Kodieren
         *    (`Uri.Builder.appendPath`) trägt hier NICHT: das Gegenstück
         *    (`MainActivity`, `uri.path`) dekodiert wieder, der Traversal wäre
         *    zurück.
         * 2. **Mitgliedschaft.** Nur Räume des Nutzers. Ein Relay ist nicht
         *    verpflichtet, unseren `#h`-Filter zu respektieren — es kann jedes
         *    Event schicken. Mehrere `h`-Tags sind ebenfalls erlaubt (unser
         *    zooid lehnt sowas heute ab, aber die App spricht mit jedem Relay,
         *    das der Nutzer als Space hinzufügt).
         */
        private fun groupTag(event: JSONObject): String? {
            val tags = event.optJSONArray("tags") ?: return null

            for (i in 0 until tags.length()) {
                val tag = tags.optJSONArray(i) ?: continue
                if (tag.optString(0) != "h") continue

                val h = tag.optString(1)
                if (!GROUP_ID.matches(h)) {
                    Log.w(TAG, "RELAY: h-Tag verworfen (unplausible Form)")
                    continue
                }
                if (!isOwnRoom(h)) continue

                return h
            }

            return null
        }

        /** Ob `h` in der Raumliste aus dem letzten Sync steht. */
        private fun isOwnRoom(h: String): Boolean {
            for (i in 0 until rooms.length()) {
                if (rooms.optString(i) == h) return true
            }

            return false
        }

        private fun finish() {
            if (done) return
            done = true
            latch.countDown()
        }
    }
}
