package com.einundzwanzig.push

import org.junit.Assert.assertEquals
import org.junit.Test

/**
 * Der Prüfstand für [RelayPollWorker.readableBody] — den Text, den ein Nutzer in
 * der Statusleiste liest.
 *
 * WARUM DIESE DATEI HIER LIEGT UND NICHT IM ANDROID-PROJEKT: `nativephp/` ist
 * generiert und komplett gitignoriert, ein Test darin wäre beim nächsten
 * `native:install` weg. Ausgeführt wird er von `scripts/run-push-kotlin.sh`
 * (`composer test:push-kotlin`), das ihn zusammen mit den Plugin-Quellen in das
 * generierte Projekt kopiert und Gradle darauf ansetzt.
 *
 * Reiner JVM-Test, kein Gerät und kein Emulator: `readableBody` fasst nichts
 * Androidspezifisches an, es ist Textarbeit. Der Rest des Workers (Socket,
 * NIP-42, WorkManager) bleibt weiterhin nur am Gerät prüfbar.
 *
 * ALLE KENNUNGEN HIER SIND ECHT — erzeugt mit `nostr-tools/nip19` (2026-08-19),
 * Prüfsumme also gültig. Das ist tragend, seit die Grenze einer Kennung über
 * bech32 bestimmt wird und nicht mehr über ein Längenmuster: eine ausgedachte
 * Zeichenkette wie `"b".repeat(58)` wäre kein bech32 (das Alphabet kennt kein
 * `b`) und würde hier nichts beweisen.
 *
 * Ausgelöst hat den Prüfstand ein Nutzer-Screenshot vom 2026-08-19: die Meldung
 * begann mit rund 100 Zeichen `nostr:nevent1…`, die eigentliche Antwort stand
 * darunter.
 */
class ReadableBodyTest {

    /** Das `nevent1` aus dem gemeldeten Screenshot, Zeichen für Zeichen. */
    private val nevent = "nevent1qvzqqqqqpypzpujqhc4ksnu9ejq4vmeqsyux47qawsn74p39pj9au6m6s5" +
        "qvwcd6qys8wumn8ghj7emjda6hqtn9d9h82mny0fmkzmn6d9njuumsv93k2tcqyzgjczx524jgplph6us" +
        "hp2ftc538hd3pdyf9zzunsyehwk64amc4un2l4tq"

    private val note = "note1424242424242424242424242424242424242424242424242424sesga3f"

    private val npub = "npub1424242424242424242424242424242424242424242424242424sg6tqgp"

    private val nprofile = "nprofile1qy0hwumn8ghj7un9d3shjtn9d9h82mny0fmkzmn6d9njuumsv93k2" +
        "qpq424242424242424242424242424242424242424242424242424sqvm44h"

    private val naddr = "naddr1qvzqqqr4gupzp24242424242424242424242424242424242424242424242424tqqzxkatjwve9gxpv"

    /** Der gemeldete Fall, Wort für Wort aus dem Screenshot. */
    @Test
    fun `nimmt das vorangestellte Zitat aus der Antwort`() {
        assertEquals(
            "Ja, ist wirklich beeindruckend, was hier entsteht",
            RelayPollWorker.readableBody(
                "nostr:$nevent\n\nJa, ist wirklich beeindruckend, was hier entsteht",
                "Zitat",
            ),
        )
    }

    /**
     * War die Nachricht NUR ein geteilter Beitrag, bleibt das Wort — eine leere
     * Notification wäre schlimmer als eine knappe.
     */
    @Test
    fun `eine Nachricht ohne eigenen Text wird zum Wort`() {
        assertEquals("Zitat", RelayPollWorker.readableBody("nostr:$nevent", "Zitat"))
        assertEquals("Zitat", RelayPollWorker.readableBody("nostr:$nevent\n\n", "Zitat"))
        assertEquals("Zitat", RelayPollWorker.readableBody("", "Zitat"))
    }

    /**
     * Mitten im Satz wird ERSETZT, nicht gelöscht: „schau dir das an" ohne jeden
     * Hinweis wäre eine andere Nachricht als die geschriebene.
     */
    @Test
    fun `ein Verweis im Fliesstext wird zum Wort`() {
        assertEquals(
            "Schau mal: Zitat — stark",
            RelayPollWorker.readableBody("Schau mal: nostr:$nevent — stark", "Zitat"),
        )
    }

    @Test
    fun `jede Kennungsart wird erkannt`() {
        for (kennung in listOf(nevent, note, naddr)) {
            assertEquals("a Zitat b", RelayPollWorker.readableBody("a nostr:$kennung b", "Zitat"))
        }

        assertEquals("a @npub14242424… b", RelayPollWorker.readableBody("a nostr:$npub b", "Zitat"))
        assertEquals("a @nprofile1qy0… b", RelayPollWorker.readableBody("a nostr:$nprofile b", "Zitat"))
    }

    /**
     * Aus dem Review (2026-08-19), zweimal am echten Kotlin-Code reproduziert:
     * mit einem Längenmuster lief der Treffer bis zum `:` der zweiten Referenz
     * durch — das `nostr` davor besteht selbst aus bech32-Zeichen —, und ein
     * nacktes `:npub1…` stand in der Meldung. Also derselbe Fehler wie der
     * gemeldete, nur eine Referenz später.
     */
    @Test
    fun `zwei Verweise ohne Trenner werden beide ersetzt`() {
        assertEquals(
            "Zitat@npub14242424…",
            RelayPollWorker.readableBody("nostr:$nevent" + "nostr:$npub", "Zitat"),
        )
    }

    /**
     * Die Gegenprobe, ebenfalls aus dem Review: direkt angehängter Klartext darf
     * nicht mitverschluckt werden. Genau das tat jede Fassung, die die Grenze
     * über ein Längenmuster suchte — „hallowelt" verschwand ersatzlos.
     */
    @Test
    fun `Text direkt hinter einer Kennung bleibt erhalten`() {
        assertEquals(
            "Schau Zitathallowelt gleich an",
            RelayPollWorker.readableBody("Schau nostr:${nevent}hallowelt gleich an", "Zitat"),
        )
    }

    /**
     * Was keine gültige Prüfsumme hat, ist kein Verweis, sondern Text, der so
     * aussieht — und bleibt deshalb unangetastet.
     */
    @Test
    fun `eine kaputte Kennung bleibt stehen statt ersetzt zu werden`() {
        // Ein einziges Zeichen der Prüfsumme verdreht — bech32 fängt das.
        val kaputt = nevent.dropLast(1) + "p"

        assertEquals("vorher nostr:$kaputt nachher", RelayPollWorker.readableBody("vorher nostr:$kaputt nachher", "Zitat"))
        assertEquals("nostr:", RelayPollWorker.readableBody("nostr:", "Zitat"))
        assertEquals("nostr:nsec1abc", RelayPollWorker.readableBody("nostr:nsec1abc", "Zitat"))
    }

    /** Ein echter Verweis endet auch an Satzzeichen, nicht nur am Leerzeichen. */
    @Test
    fun `ein Verweis am Satzende wird erkannt`() {
        assertEquals("Guck: Zitat.", RelayPollWorker.readableBody("Guck: nostr:$note.", "Zitat"))
    }

    @Test
    fun `eine Erwaehnung schrumpft auf eine lesbare Kennung`() {
        assertEquals(
            "Frag mal @npub14242424… danach",
            RelayPollWorker.readableBody("Frag mal nostr:$npub danach", "Zitat"),
        )
    }

    /** Gleiche Regel wie `chatMarkup.ts stripInlineMarkup` im Web-Chat. */
    @Test
    fun `Auszeichnung faellt weg statt sichtbar zu stehen`() {
        assertEquals(
            "21Meetup von gestern mit code",
            RelayPollWorker.readableBody("**21Meetup** von ~~gestern~~ mit `code`", "Zitat"),
        )
    }

    /**
     * Das Label kommt über die Bridge vom Client. In einem Regex-Ersatz-String
     * wäre `$1` eine Gruppenreferenz und würfe zur Laufzeit — hier ist es Text.
     */
    @Test
    fun `ein Label mit Dollarzeichen wirft nicht`() {
        assertEquals("a \$1 b", RelayPollWorker.readableBody("a nostr:$nevent b", "\$1"))
    }

    /**
     * Ein Relay kann beliebig viel schicken. Der Scan muss davon unbeeindruckt
     * bleiben — angezeigt werden ohnehin 400 Zeichen.
     */
    @Test
    fun `viel Text ohne gueltige Kennung laeuft durch`() {
        val muell = "nostr:".repeat(2_000)

        assertEquals(muell.take(2_000), RelayPollWorker.readableBody(muell, "Zitat"))
    }

    /** Der Normalfall darf nicht angefasst werden. */
    @Test
    fun `gewoehnlicher Text bleibt, wie er ist`() {
        val text = "Mir gefällt das auch jeden Tag noch besser"

        assertEquals(text, RelayPollWorker.readableBody(text, "Zitat"))
    }
}
