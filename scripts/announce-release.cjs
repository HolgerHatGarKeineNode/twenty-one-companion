// TWENTY ONE Companion — HEADLESS release announcement as a Nostr note (kind 1, pure Node).
//
//   node scripts/announce-release.cjs --version 1.1.0            # Preview (dry run)
//   node scripts/announce-release.cjs --version 1.1.0 --go       # LIVE publish!
//   node scripts/announce-release.cjs --text-file <file> …       # Override the text path
//
// What it does:
//   Builds an announcement note for a new app release. The release text itself is
//   read from dist/v<version>/announce.txt (placed there beforehand by Claude as a
//   short, marketing-friendly text; --text-file overrides the path). Below it, all
//   download channels (Zapstore, Obtainium, GitHub) are appended automatically with
//   emojis in a clean layout. All published texts are English (consistent with the
//   English-only release policy from v1.1.0 on).
//
// Signing is done via NIP-46 (Amber bunker) with NOSTR_CLIENT_SK + NOSTR_BUNKER_URL from .env.
// Broadcast follows the gossip/NIP-65 strategy: the publisher's outbox relays (kind 10002)
// are loaded; the fallback is the default relays below.
//
// Gates (always run, before signing):
//   - release text is non-empty and ≤ 1000 characters
//   - version is a real version (not "DEBUG"/empty)
// With --go additionally:
//   - the signer pubkey must match PUBLISHER_NPUB (only announce as the official
//     publisher), then relay verification via the event ID.
//
// Preview is the built-in dry run: without --go NOTHING is signed/sent.
//
// Requirement for --go: NOSTR_BUNKER_URL + NOSTR_CLIENT_SK in .env, Amber online.
const fs = require('fs')
const path = require('path')
const ROOT = path.join(__dirname, '..')
const { SimplePool, useWebSocketImplementation } = require('nostr-tools/pool')
const { BunkerSigner, parseBunkerInput } = require('nostr-tools/nip46')
const nip19 = require('nostr-tools/nip19')
if (typeof WebSocket !== 'undefined') useWebSocketImplementation(WebSocket)

// ── Project constants (single source of truth for the links) ────────────────
const REPO = 'https://github.com/HolgerHatGarKeineNode/twenty-one-companion'
const PUBLISHER_NPUB = 'npub1pt0kw36ue3w2g4haxq3wgm6a2fhtptmzsjlc2j2vphtcgle72qesgpjyc6'
const LINKS = {
  github: REPO + '/releases/latest',
  obtainium: 'https://apps.obtainium.imranr.dev/redirect.html?r=obtainium://add/' + REPO,
  zapstore: 'https://zapstore.dev',
  source: REPO,
}
// Topic tags (NIP-12 t-tags) + matching hashtags in the content.
const TOPICS = ['bitcoin', 'nostr', 'einundzwanzig', 'android']

// Fixed layout building blocks (English-only, per the release policy).
// Functions receive the version; ${...} links come from LINKS.
const L10N = {
  header: (v) => `🟧⚡ TWENTY ONE Companion v${v} is out! 🎉`,
  downloadsIntro: '📥 Get it / update now:',
  zapstore: `⚡ Zapstore (recommended — Nostr-signed, auto-verified updates):\n   ① Install the Zapstore app ② search "TWENTY ONE" ③ tap install.\n   ${LINKS.zapstore}`,
  obtainium: `📦 Obtainium (auto-updates straight from GitHub):\n   ① Open this link in Obtainium → "Add app" ② it self-updates on every release.\n   ${LINKS.obtainium}`,
  github: `🐙 GitHub (manual — APK + signature verification):\n   ① Download the .apk from the latest release ② verify ③ install.\n   ${LINKS.github}`,
  source: `🛠️ Source code (open source, MIT):\n   ${LINKS.source}`,
}
// Default broadcast relays in case the publisher has no kind 10002.
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
let version = get('--version')

const env = loadEnv()
if (!version) version = env.NATIVEPHP_APP_VERSION
if (!version || version === 'DEBUG') {
  console.error('GATE FAIL: no real version (pass --version <x.y.z> or set NATIVEPHP_APP_VERSION in .env)')
  process.exit(1)
}

// The default text path follows the dist convention; --text-file overrides it.
const textFile = get('--text-file') || path.join('dist', 'v' + version, 'announce.txt')

;(async () => {
  // 1) Load the release text + local gates.
  const textPath = path.resolve(textFile)
  if (!fs.existsSync(textPath)) {
    console.error('GATE FAIL: announce text missing: ' + textPath)
    console.error('  → First place the marketing-ready release text there (Claude generates it from dist/v' + version + '/).')
    process.exit(1)
  }
  const text = fs.readFileSync(textPath, 'utf8').trim()
  if (!text || text.length > 1000) {
    console.error('GATE FAIL (text): ' + JSON.stringify({ empty: !text, len: text.length, max: 1000 }))
    process.exit(1)
  }

  // 2) Build the content in the fixed layout (release text + download channels + hashtags).
  const t = L10N
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

  // 3) Tags: client + topic t-tags + p-tags for every nostr:npub mentioned in the
  //    text (NIP-27), so the mentioned profiles actually get notified.
  const mentionPubkeys = [...new Set((content.match(/nostr:(npub1[0-9a-z]+)/g) || [])
    .map(m => m.replace('nostr:', '')))]
    .map(npub => { try { return nip19.decode(npub).data } catch (e) { return null } })
    .filter(Boolean)
  const tags = [
    ['client', 'TWENTY ONE Companion'],
    ...TOPICS.map(topic => ['t', topic]),
    ...mentionPubkeys.map(pk => ['p', pk]),
  ]

  // 4) Preview (no signing) — built-in dry run.
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

  // 5) Signer from .env (Amber bunker).
  const bunkerUrl = process.env.NOSTR_BUNKER_URL || env.NOSTR_BUNKER_URL
  const clientSk = process.env.NOSTR_CLIENT_SK || env.NOSTR_CLIENT_SK
  if (!bunkerUrl || !clientSk) { console.error('NOSTR_BUNKER_URL/NOSTR_CLIENT_SK missing (.env)'); process.exit(1) }
  const bp = await parseBunkerInput(bunkerUrl)
  if (!bp) { console.error('invalid bunker:// URL'); process.exit(1) }

  const pool = new SimplePool()
  const signer = BunkerSigner.fromBunker(hexToBytes(clientSk), bp, { pool, onauth: (u) => console.error('⚠ Amber auth_url: ' + u) })
  console.error('▶ Connecting to the bunker (Amber)…')
  await Promise.race([signer.connect(), new Promise((_, r) => setTimeout(() => r(new Error('connect timeout 60s')), 60000))])
  const pubkey = await signer.getPublicKey()

  // 6) Gate: only announce as the official publisher.
  const expected = nip19.decode(PUBLISHER_NPUB).data
  if (pubkey !== expected) {
    console.error('GATE FAIL: signer ' + pubkey.slice(0, 12) + '… is not the publisher (' + PUBLISHER_NPUB.slice(0, 16) + '…)')
    process.exit(1)
  }

  // 7) NIP-65: load the publisher's outbox relays (gossip strategy).
  console.error('▶ Loading NIP-65 outbox relays (kind 10002)…')
  const relayListEvs = await pool.querySync(BOOTSTRAP, { kinds: [10002], authors: [pubkey], limit: 1 }, { maxWait: 8000 })
  relayListEvs.sort((a, b) => b.created_at - a.created_at)
  let broadcastRelays
  if (relayListEvs.length) {
    broadcastRelays = relayListEvs[0].tags
      .filter(t => t[0] === 'r' && (!t[2] || t[2] === 'write'))
      .map(t => t[1])
    console.error('Outbox relays:', broadcastRelays.join(', '))
  } else {
    console.error('no kind 10002 found — falling back to default relays')
    broadcastRelays = DEFAULT_RELAYS
  }
  if (!broadcastRelays.length) { broadcastRelays = DEFAULT_RELAYS }

  // 8) Sign + broadcast.
  const signed = await signer.signEvent({ kind: 1, created_at: Math.floor(Date.now() / 1000), tags, content })
  const sends = await Promise.allSettled(pool.publish(broadcastRelays, signed))
  const accepted = []
  sends.forEach((s, i) => { if (s.status === 'fulfilled') accepted.push(broadcastRelays[i]) })

  // 9) Relay verification via the event ID.
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
