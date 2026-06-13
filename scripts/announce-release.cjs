// EINUNDZWANZIG Mobile — HEADLESS Release-Ankündigung als Nostr-Note (kind 1, reines Node).
//
//   node scripts/announce-release.cjs --version 1.1.0            # Preview (Dry-Run, EN)
//   node scripts/announce-release.cjs --version 1.1.0 --lang de  # Deutsches Layout
//   node scripts/announce-release.cjs --version 1.1.0 --go       # LIVE veröffentlichen!
//   node scripts/announce-release.cjs --text-file <datei> …      # Text-Pfad überschreiben
//
// --lang en|de steuert NUR die festen Bausteine (Header, Download-Labels). Der
// Body aus announce.txt muss in der passenden Sprache verfasst sein. Standard: en.
//
// Was es tut:
//   Baut eine Ankündigungs-Note für ein neues App-Release. Der eigentliche
//   Release-Text wird aus dist/v<version>/announce.txt gelesen (von Claude als
//   kurzer, marketing-tauglicher Text vorab dort abgelegt; --text-file übersteuert
//   den Pfad). Darunter werden automatisch alle Download-Kanäle (Zapstore,
//   Obtainium, GitHub) mit Emojis in sauberem Layout angehängt. Sprache der festen
//   Bausteine: Englisch (konsistent mit der englischen Release-Politik ab v1.1.0).
//
// Signiert wird per NIP-46 (Amber-Bunker) mit NOSTR_CLIENT_SK + NOSTR_BUNKER_URL aus .env.
// Broadcast folgt der Gossip-/NIP-65-Strategie: die Outbox-Relays (kind 10002)
// des Publishers werden geladen; Fallback sind die Default-Relays unten.
//
// Gates (laufen IMMER, vor dem Signieren):
//   - Release-Text nicht leer und ≤ 1000 Zeichen
//   - Version ist eine echte Version (nicht "DEBUG"/leer)
// Bei --go zusätzlich:
//   - Signer-Pubkey muss dem PUBLISHER_NPUB entsprechen (nur als offizieller
//     Herausgeber ankündigen), danach Relay-Verifikation über die Event-ID.
//
// Preview ist der eingebaute Dry-Run: ohne --go wird NICHTS signiert/gesendet.
//
// Voraussetzung bei --go: NOSTR_BUNKER_URL + NOSTR_CLIENT_SK in .env, Amber online.
const fs = require('fs')
const path = require('path')
const ROOT = path.join(__dirname, '..')
const { SimplePool, useWebSocketImplementation } = require('nostr-tools/pool')
const { BunkerSigner, parseBunkerInput } = require('nostr-tools/nip46')
const nip19 = require('nostr-tools/nip19')
if (typeof WebSocket !== 'undefined') useWebSocketImplementation(WebSocket)

// ── Projekt-Konstanten (Single Source of Truth für die Links) ───────────────
const REPO = 'https://github.com/HolgerHatGarKeineNode/einundzwanzig-mobile-app'
const PUBLISHER_NPUB = 'npub1pt0kw36ue3w2g4haxq3wgm6a2fhtptmzsjlc2j2vphtcgle72qesgpjyc6'
const LINKS = {
  github: REPO + '/releases/latest',
  obtainium: 'https://apps.obtainium.imranr.dev/redirect.html?r=obtainium://add/' + REPO,
  zapstore: 'https://zapstore.dev',
  source: REPO,
}
// Topic-Tags (NIP-12 t-Tags) + zugehörige Hashtags im content.
const TOPICS = ['bitcoin', 'nostr', 'einundzwanzig', 'android']

// Feste Layout-Bausteine je Sprache (--lang en|de). Standard: en (Release-Politik).
// Funktionen erhalten die Version; ${...}-Links kommen aus LINKS.
const L10N = {
  en: {
    header: (v) => `🟧⚡ EINUNDZWANZIG Mobile v${v} is out! 🎉`,
    downloadsIntro: '📥 Get it / update now:',
    zapstore: `⚡ Zapstore (Nostr-signed, auto-verified):\n   ${LINKS.zapstore} — search for "EINUNDZWANZIG"`,
    obtainium: `📦 Obtainium (auto-updates from GitHub):\n   ${LINKS.obtainium}`,
    github: `🐙 GitHub release (APK + verification):\n   ${LINKS.github}`,
    source: `🛠️ Source code (open source, MIT):\n   ${LINKS.source}`,
  },
  de: {
    header: (v) => `🟧⚡ EINUNDZWANZIG Mobile v${v} ist da! 🎉`,
    downloadsIntro: '📥 Jetzt installieren / aktualisieren:',
    zapstore: `⚡ Zapstore (Nostr-signiert, automatisch verifiziert):\n   ${LINKS.zapstore} — nach "EINUNDZWANZIG" suchen`,
    obtainium: `📦 Obtainium (automatische Updates aus GitHub):\n   ${LINKS.obtainium}`,
    github: `🐙 GitHub-Release (APK + Verifikation):\n   ${LINKS.github}`,
    source: `🛠️ Quellcode (Open Source, MIT):\n   ${LINKS.source}`,
  },
}
// Default-Broadcast-Relays, falls der Publisher kein kind 10002 hat.
const DEFAULT_RELAYS = [
  'wss://relay.damus.io', 'wss://nos.lol', 'wss://nostr.einundzwanzig.space',
  'wss://relay.nostr.band', 'wss://relay.primal.net',
]
const BOOTSTRAP = [
  'wss://relay.damus.io', 'wss://nos.lol', 'wss://nostr.einundzwanzig.space',
  'wss://relay.nostr.band', 'wss://purplepag.es',
]

function loadEnv() {
  const p = path.join(ROOT, '.env'); const out = {}
  if (!fs.existsSync(p)) return out
  for (const line of fs.readFileSync(p, 'utf8').split('\n')) {
    if (line.trim().startsWith('#')) continue
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/)
    if (m) out[m[1]] = m[2].replace(/^["']|["']$/g, '')
  }
  return out
}
function hexToBytes(hex) {
  const clean = String(hex || '').trim(); const out = new Uint8Array(clean.length / 2)
  for (let i = 0; i < out.length; i++) out[i] = parseInt(clean.slice(i * 2, i * 2 + 2), 16)
  return out
}

const args = process.argv.slice(2)
const get = (flag) => { const i = args.indexOf(flag); return i >= 0 ? args[i + 1] : undefined }
const GO = args.includes('--go')
const lang = (get('--lang') || 'en').toLowerCase()
if (!L10N[lang]) { console.error('GATE FAIL: --lang muss "en" oder "de" sein'); process.exit(1) }
let version = get('--version')

const env = loadEnv()
if (!version) version = env.NATIVEPHP_APP_VERSION
if (!version || version === 'DEBUG') {
  console.error('GATE FAIL: keine echte Version (--version <x.y.z> übergeben oder NATIVEPHP_APP_VERSION in .env setzen)')
  process.exit(1)
}

// Default-Textpfad folgt der dist-Konvention; --text-file übersteuert ihn.
const textFile = get('--text-file') || path.join('dist', 'v' + version, 'announce.txt')

;(async () => {
  // 1) Release-Text laden + lokale Gates.
  const textPath = path.resolve(textFile)
  if (!fs.existsSync(textPath)) {
    console.error('GATE FAIL: Announce-Text fehlt: ' + textPath)
    console.error('  → Erst den marketing-tauglichen Release-Text dort ablegen (Claude erzeugt ihn aus dist/v' + version + '/).')
    process.exit(1)
  }
  const text = fs.readFileSync(textPath, 'utf8').trim()
  if (!text || text.length > 1000) {
    console.error('GATE FAIL (Text): ' + JSON.stringify({ empty: !text, len: text.length, max: 1000 }))
    process.exit(1)
  }

  // 2) content im festen Layout bauen (Release-Text + Download-Kanäle + Hashtags).
  const t = L10N[lang]
  const header = t.header(version)
  const downloads = [
    t.downloadsIntro,
    '',
    t.zapstore,
    '',
    t.obtainium,
    '',
    t.github,
    '',
    t.source,
  ].join('\n')
  const hashtags = TOPICS.map(topic => '#' + topic).join(' ')
  const content = `${header}\n\n${text}\n\n${downloads}\n\n${hashtags}`

  // 3) Tags: client + Topic-t-Tags.
  const tags = [
    ['client', 'EINUNDZWANZIG'],
    ...TOPICS.map(topic => ['t', topic]),
  ]

  // 4) Preview (kein Signieren) — eingebauter Dry-Run.
  if (!GO) {
    console.log(JSON.stringify({
      ok: true, stage: 'preview', version,
      gates: { textLen: text.length, contentLen: content.length },
      kind: 1, tags,
    }, null, 2))
    console.log('\n──────── content preview ────────\n')
    console.log(content)
    console.log('\n─────────────────────────────────')
    process.exit(0)
  }

  // 5) Signer aus .env (Amber-Bunker).
  const bunkerUrl = process.env.NOSTR_BUNKER_URL || env.NOSTR_BUNKER_URL
  const clientSk = process.env.NOSTR_CLIENT_SK || env.NOSTR_CLIENT_SK
  if (!bunkerUrl || !clientSk) { console.error('NOSTR_BUNKER_URL/NOSTR_CLIENT_SK fehlen (.env)'); process.exit(1) }
  const bp = await parseBunkerInput(bunkerUrl)
  if (!bp) { console.error('ungültige bunker://-URL'); process.exit(1) }

  const pool = new SimplePool()
  const signer = BunkerSigner.fromBunker(hexToBytes(clientSk), bp, { pool, onauth: (u) => console.error('⚠ Amber auth_url: ' + u) })
  console.error('▶ Verbinde mit Bunker (Amber)…')
  await Promise.race([signer.connect(), new Promise((_, r) => setTimeout(() => r(new Error('connect timeout 60s')), 60000))])
  const pubkey = await signer.getPublicKey()

  // 6) Gate: nur als offizieller Publisher ankündigen.
  const expected = nip19.decode(PUBLISHER_NPUB).data
  if (pubkey !== expected) {
    console.error('GATE FAIL: Signer ' + pubkey.slice(0, 12) + '… ist nicht der Publisher (' + PUBLISHER_NPUB.slice(0, 16) + '…)')
    process.exit(1)
  }

  // 7) NIP-65: Outbox-Relays des Publishers laden (Gossip-Strategie).
  console.error('▶ Lade NIP-65 Outbox-Relays (kind 10002)…')
  const relayListEvs = await pool.querySync(BOOTSTRAP, { kinds: [10002], authors: [pubkey], limit: 1 }, { maxWait: 8000 })
  relayListEvs.sort((a, b) => b.created_at - a.created_at)
  let broadcastRelays
  if (relayListEvs.length) {
    broadcastRelays = relayListEvs[0].tags
      .filter(t => t[0] === 'r' && (!t[2] || t[2] === 'write'))
      .map(t => t[1])
    console.error('Outbox-Relays:', broadcastRelays.join(', '))
  } else {
    console.error('kein kind 10002 gefunden — Fallback auf Default-Relays')
    broadcastRelays = DEFAULT_RELAYS
  }
  if (!broadcastRelays.length) { broadcastRelays = DEFAULT_RELAYS }

  // 8) Signieren + Broadcast.
  const signed = await signer.signEvent({ kind: 1, created_at: Math.floor(Date.now() / 1000), tags, content })
  const sends = await Promise.allSettled(pool.publish(broadcastRelays, signed))
  const accepted = []
  sends.forEach((s, i) => { if (s.status === 'fulfilled') accepted.push(broadcastRelays[i]) })

  // 9) Relay-Verifikation über die Event-ID.
  await new Promise(r => setTimeout(r, 800))
  const verify = await pool.querySync((accepted.length ? accepted : broadcastRelays).slice(0, 4), { ids: [signed.id] }, { maxWait: 5000 })
  const verified = verify.some(e => e.id === signed.id)

  let nevent = null
  try { nevent = nip19.neventEncode({ id: signed.id, author: pubkey, kind: 1, relays: accepted.slice(0, 3) }) } catch (e) { /* best-effort */ }

  console.log(JSON.stringify({
    ok: verified, stage: verified ? 'published+verified' : 'published-unverified',
    version, eventId: signed.id, nevent,
    relaysAccepted: accepted,
    relaysFailed: sends.map((s, i) => s.status === 'rejected' ? broadcastRelays[i] : null).filter(Boolean),
  }, null, 2))
  console.log('\n──────── published content ────────\n')
  console.log(content)
  console.log('\n───────────────────────────────────')

  pool.close([...BOOTSTRAP, ...broadcastRelays])
  setTimeout(() => process.exit(verified ? 0 : 1), 200)
})()
