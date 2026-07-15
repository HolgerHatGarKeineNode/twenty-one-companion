package com.einundzwanzig.push

import android.util.Base64
import fr.acinq.secp256k1.Secp256k1
import org.json.JSONArray
import org.json.JSONObject
import java.nio.charset.StandardCharsets
import java.security.MessageDigest
import java.security.SecureRandom
import java.util.Arrays

/**
 * NIP-01-Signieren und NIP-44-v2-Verschlüsselung für den Hintergrund-Worker.
 *
 * Portiert aus Flotilla (MIT), AndroidPushFallbackWorker.kt:280-513 — 1:1, weil
 * Krypto niemand „verbessern" sollte. Gebraucht für NIP-46: der Client hält dort
 * einen eigenen Schlüssel, mit dem er die RPC-Hülle (kind 24133) selbst signiert
 * und ihren Inhalt an den Bunker verschlüsselt. Der Kontoschlüssel bleibt beim
 * Signer.
 *
 * Alles gibt bei Fehlern "" bzw. leere Bytes zurück statt zu werfen: der Worker
 * hat keine UI, ein Fehlschlag bedeutet schlicht „keine Notification".
 */
object NostrCrypto {

    private val SECP = Secp256k1.get()

    /** NIP-01-Event-ID: sha256 über die kanonische Serialisierung. */
    fun computeEventId(event: JSONObject): String = try {
        val serialized = JSONArray().apply {
            put(0)
            put(event.optString("pubkey", ""))
            put(event.optLong("created_at", 0))
            put(event.optInt("kind", 0))
            put(event.optJSONArray("tags") ?: JSONArray())
            put(event.optString("content", ""))
        }
        // JSONObject escapt Slashes (/ → \/), NIP-01 hasht sie unescaped.
        val canonical = serialized.toString().replace("\\/", "/")
        bytesToHex(sha256(canonical.toByteArray(StandardCharsets.UTF_8)))
    } catch (_: Exception) {
        ""
    }

    /** x-only-Pubkey (32 Byte hex) zu einem Secret. */
    fun deriveXOnlyPubkey(secretHex: String): String {
        val secret = hexToBytes(secretHex)
        if (secret.size != 32 || !SECP.secKeyVerify(secret)) return ""
        val pubkey65 = try { SECP.pubkeyCreate(secret) } catch (_: Exception) { return "" }
        if (pubkey65.size != 65) return ""
        return bytesToHex(Arrays.copyOfRange(pubkey65, 1, 33))
    }

    /** Signiert das Event mit einem lokalen Secret; gibt das fertige Event-JSON zurück. */
    fun signWithNip01Secret(secretHex: String, eventJson: String, expectedPubkey: String): String = try {
        val secret = hexToBytes(secretHex)
        if (secret.size != 32) {
            ""
        } else {
            val event = JSONObject(eventJson)
            var pk = event.optString("pubkey", expectedPubkey)
            if (pk.isEmpty()) pk = deriveXOnlyPubkey(secretHex)
            if (pk.isEmpty()) {
                ""
            } else {
                event.put("pubkey", pk)
                val id = computeEventId(event)
                val sig = if (id.isEmpty()) "" else schnorrSign(secretHex, id)
                if (sig.isEmpty()) {
                    ""
                } else {
                    event.put("id", id)
                    event.put("sig", sig)
                    event.toString()
                }
            }
        }
    } catch (_: Exception) {
        ""
    }

    private fun schnorrSign(secretHex: String, messageHex: String): String {
        val sk = hexToBytes(secretHex)
        val msg = hexToBytes(messageHex)
        if (sk.size != 32 || msg.size != 32 || !SECP.secKeyVerify(sk)) return ""
        val aux = ByteArray(32).also { SecureRandom().nextBytes(it) }
        val sig = try { SECP.signSchnorr(msg, sk, aux) } catch (_: Exception) { return "" }
        if (sig.size != 64) return ""
        return bytesToHex(sig)
    }

    // ---- NIP-44 v2: ECDH + HKDF + ChaCha20 + HMAC-SHA256 ----

    /** Gemeinsamer Schlüssel für das Paar (eigenes Secret, fremder Pubkey). */
    fun nip44ConversationKey(secretHex: String, theirPubkey: String): ByteArray {
        val sk = hexToBytes(secretHex)
        val pk = hexToBytes("02$theirPubkey")
        if (sk.size != 32 || pk.size != 33) return ByteArray(0)
        val shared = try { SECP.pubKeyTweakMul(pk, sk) } catch (_: Exception) { return ByteArray(0) }
        if (shared.size != 65) return ByteArray(0)
        val sharedX = Arrays.copyOfRange(shared, 1, 33)
        return hkdfExtract(sharedX, "nip44-v2".toByteArray(StandardCharsets.UTF_8))
    }

    fun encryptNip44(plaintext: String, conversationKey: ByteArray): String = try {
        val nonce = ByteArray(32).also { SecureRandom().nextBytes(it) }
        val keys = hkdfExpand(conversationKey, nonce, 76)
        val ciphertext = chacha20(keys.sliceArray(0 until 32), keys.sliceArray(32 until 44), nip44Pad(plaintext))
        val mac = hmacSha256(keys.sliceArray(44 until 76), nonce, ciphertext)
        val payload = ByteArray(1 + 32 + ciphertext.size + 32)
        payload[0] = 2
        System.arraycopy(nonce, 0, payload, 1, 32)
        System.arraycopy(ciphertext, 0, payload, 33, ciphertext.size)
        System.arraycopy(mac, 0, payload, 33 + ciphertext.size, 32)
        Base64.encodeToString(payload, Base64.NO_WRAP)
    } catch (_: Exception) {
        ""
    }

    fun decryptNip44(payload: String, conversationKey: ByteArray): String = try {
        if (payload.isEmpty() || payload[0] == '#') {
            ""
        } else {
            val data = Base64.decode(payload, Base64.NO_WRAP)
            if (data.size < 99 || data[0] != 2.toByte()) {
                ""
            } else {
                val nonce = data.sliceArray(1 until 33)
                val ciphertext = data.sliceArray(33 until data.size - 32)
                val mac = data.sliceArray(data.size - 32 until data.size)
                val keys = hkdfExpand(conversationKey, nonce, 76)
                val expectedMac = hmacSha256(keys.sliceArray(44 until 76), nonce, ciphertext)
                if (!expectedMac.contentEquals(mac)) {
                    ""
                } else {
                    nip44Unpad(chacha20(keys.sliceArray(0 until 32), keys.sliceArray(32 until 44), ciphertext))
                }
            }
        }
    } catch (_: Exception) {
        ""
    }

    private fun hkdfExtract(ikm: ByteArray, salt: ByteArray): ByteArray {
        val mac = javax.crypto.Mac.getInstance("HmacSHA256")
        mac.init(javax.crypto.spec.SecretKeySpec(salt, "HmacSHA256"))
        return mac.doFinal(ikm)
    }

    private fun hkdfExpand(prk: ByteArray, info: ByteArray, length: Int): ByteArray {
        val mac = javax.crypto.Mac.getInstance("HmacSHA256")
        val result = ByteArray(length)
        var prev = ByteArray(0)
        var offset = 0
        var counter = 1
        while (offset < length) {
            mac.init(javax.crypto.spec.SecretKeySpec(prk, "HmacSHA256"))
            mac.update(prev)
            mac.update(info)
            mac.update(counter.toByte())
            prev = mac.doFinal()
            val toCopy = minOf(prev.size, length - offset)
            System.arraycopy(prev, 0, result, offset, toCopy)
            offset += toCopy
            counter++
        }
        return result
    }

    private fun hmacSha256(key: ByteArray, vararg parts: ByteArray): ByteArray {
        val mac = javax.crypto.Mac.getInstance("HmacSHA256")
        mac.init(javax.crypto.spec.SecretKeySpec(key, "HmacSHA256"))
        for (part in parts) mac.update(part)
        return mac.doFinal()
    }

    /** ChaCha20-Blockfunktion nach RFC 8439. */
    private fun chacha20Block(key: ByteArray, counter: Int, nonce: ByteArray): ByteArray {
        fun Int.rotl(n: Int) = (this shl n) or (this ushr (32 - n))
        val state = IntArray(16)
        state[0] = 0x61707865; state[1] = 0x3320646e; state[2] = 0x79622d32; state[3] = 0x6b206574
        for (i in 0..7) {
            state[4 + i] = (key[i * 4].toInt() and 0xFF) or
                ((key[i * 4 + 1].toInt() and 0xFF) shl 8) or
                ((key[i * 4 + 2].toInt() and 0xFF) shl 16) or
                ((key[i * 4 + 3].toInt() and 0xFF) shl 24)
        }
        state[12] = counter
        for (i in 0..2) {
            state[13 + i] = (nonce[i * 4].toInt() and 0xFF) or
                ((nonce[i * 4 + 1].toInt() and 0xFF) shl 8) or
                ((nonce[i * 4 + 2].toInt() and 0xFF) shl 16) or
                ((nonce[i * 4 + 3].toInt() and 0xFF) shl 24)
        }
        val working = state.copyOf()
        repeat(10) {
            fun quarterRound(a: Int, b: Int, c: Int, d: Int) {
                working[a] += working[b]; working[d] = (working[d] xor working[a]).rotl(16)
                working[c] += working[d]; working[b] = (working[b] xor working[c]).rotl(12)
                working[a] += working[b]; working[d] = (working[d] xor working[a]).rotl(8)
                working[c] += working[d]; working[b] = (working[b] xor working[c]).rotl(7)
            }
            quarterRound(0, 4, 8, 12); quarterRound(1, 5, 9, 13)
            quarterRound(2, 6, 10, 14); quarterRound(3, 7, 11, 15)
            quarterRound(0, 5, 10, 15); quarterRound(1, 6, 11, 12)
            quarterRound(2, 7, 8, 13); quarterRound(3, 4, 9, 14)
        }
        val out = ByteArray(64)
        for (i in 0..15) {
            val v = working[i] + state[i]
            out[i * 4] = v.toByte()
            out[i * 4 + 1] = (v ushr 8).toByte()
            out[i * 4 + 2] = (v ushr 16).toByte()
            out[i * 4 + 3] = (v ushr 24).toByte()
        }
        return out
    }

    private fun chacha20(key: ByteArray, nonce: ByteArray, data: ByteArray): ByteArray {
        val out = ByteArray(data.size)
        var counter = 0
        var offset = 0
        while (offset < data.size) {
            val block = chacha20Block(key, counter, nonce)
            val len = minOf(64, data.size - offset)
            for (i in 0 until len) out[offset + i] = (data[offset + i].toInt() xor block[i].toInt()).toByte()
            offset += len
            counter++
        }
        return out
    }

    private fun nip44CalcPaddedLen(len: Int): Int {
        if (len <= 32) return 32
        val nextPower = 1 shl (Math.floor(Math.log((len - 1).toDouble()) / Math.log(2.0)).toInt() + 1)
        val chunk = if (nextPower <= 256) 32 else nextPower / 8
        return chunk * ((len - 1) / chunk + 1)
    }

    private fun nip44Pad(plaintext: String): ByteArray {
        val unpadded = plaintext.toByteArray(StandardCharsets.UTF_8)
        val padded = ByteArray(2 + nip44CalcPaddedLen(unpadded.size))
        padded[0] = (unpadded.size ushr 8).toByte()
        padded[1] = unpadded.size.toByte()
        System.arraycopy(unpadded, 0, padded, 2, unpadded.size)
        return padded
    }

    private fun nip44Unpad(padded: ByteArray): String {
        val len = ((padded[0].toInt() and 0xFF) shl 8) or (padded[1].toInt() and 0xFF)
        if (len == 0 || len > padded.size - 2) return ""
        return String(padded, 2, len, StandardCharsets.UTF_8)
    }

    private fun sha256(input: ByteArray): ByteArray = try {
        MessageDigest.getInstance("SHA-256").digest(input)
    } catch (_: Exception) {
        ByteArray(32)
    }

    private fun hexToBytes(hex: String?): ByteArray {
        var s = hex?.trim()?.lowercase() ?: ""
        if (s.startsWith("0x")) s = s.substring(2)
        if (s.length % 2 == 1) s = "0$s"
        val bytes = ByteArray(s.length / 2)
        var i = 0
        while (i < s.length) {
            val hi = Character.digit(s[i], 16)
            val lo = Character.digit(s[i + 1], 16)
            if (hi < 0 || lo < 0) return ByteArray(0)
            bytes[i / 2] = ((hi shl 4) + lo).toByte()
            i += 2
        }
        return bytes
    }

    private fun bytesToHex(bytes: ByteArray): String {
        val hex = "0123456789abcdef".toCharArray()
        val chars = CharArray(bytes.size * 2)
        for (i in bytes.indices) {
            val v = bytes[i].toInt() and 0xFF
            chars[i * 2] = hex[v ushr 4]
            chars[i * 2 + 1] = hex[v and 0x0F]
        }
        return String(chars)
    }
}
