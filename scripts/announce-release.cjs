// TWENTY ONE Companion — HEADLESS release announcement as a Nostr note (kind 1, pure Node).
//
//   node scripts/announce-release.cjs --version 1.1.0            # Preview (dry run)
//   node scripts/announce-release.cjs --version 1.1.0 --go       # LIVE publish!
//   node scripts/announce-release.cjs --version 1.1.0 --short    # Hotfix: header + changes only, no download block
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
//   - release text is non-empty and ≤ 1500 characters (higher than before: the
//     new format weaves inline image URLs per feature, which cost ~90 chars each)
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
const DIV = '━━━━━━━━━━━━━━━━━━'
const L10N = {
  header: (v) => `⚡ TWENTY ONE Companion · v${v}`,
  headerShort: (v) => `🩹 TWENTY ONE Companion · v${v} hotfix`,
  // Download channels are one-liners now — regulars already know the drill, just links.
  downloadsIntro: '📥 Get it / update:',
  zapstore: `⚡ Zapstore (recommended) → ${LINKS.zapstore}`,
  obtainium: `📦 Obtainium (auto-updates) → ${LINKS.obtainium}`,
  github: `🐙 GitHub (APK + signature) → ${LINKS.github}`,
  source: `🛠️ Source, MIT → ${LINKS.source}`,
}
// Renders the composed note as a standalone HTML file that mimics how a Nostr
// client displays it: bare image URLs become inline images, links stay clickable,
// hashtags get the accent colour. The design lives HERE (authored once) and is
// reused for every release — the content is injected from the composed note, so
// no per-release design work is needed. Written to dist/v<ver>/announce-preview.html
// on every preview run; open it in a browser to eyeball the post before --go.
function buildPreviewHtml(content, version) {
  const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  const IMG_RE = /^https?:\/\/\S+\.(?:webp|png|jpe?g|gif)(?:\?\S*)?$/i
  const linkify = (s) => s.replace(/(https?:\/\/[^\s<]+)/g, (u) => '<a href="' + u + '" target="_blank" rel="noopener">' + u + '</a>')
  const tagify = (s) => s.replace(/(^|\s)(#[\p{L}\p{N}_]+)/gu, (_m, pre, tag) => pre + '<span class="tag">' + tag + '</span>')
  const body = content.split('\n').map((raw) => {
    const t = raw.trim()
    if (t === '') { return '<div class="sp"></div>' }
    if (IMG_RE.test(t)) { return '<a class="shot" href="' + t + '" target="_blank" rel="noopener"><img src="' + t + '" alt="feature screenshot" loading="lazy"></a>' }
    if (/^[·•━—\-\s]+$/.test(t)) { return '<div class="ln div">' + esc(t) + '</div>' }
    return '<div class="ln">' + tagify(linkify(esc(raw))) + '</div>'
  }).join('\n')
  const npub = PUBLISHER_NPUB.slice(0, 12) + '…' + PUBLISHER_NPUB.slice(-6)
  return [
    '<!doctype html><html lang="en"><head><meta charset="utf-8">',
    '<meta name="viewport" content="width=device-width, initial-scale=1">',
    '<title>Nostr preview · TWENTY ONE Companion v' + esc(version) + '</title>',
    '<style>',
    ':root{--bg:#0e1013;--card:#181b21;--border:#292d36;--text:#e8eaee;--muted:#8a91a1;--accent:#f7931a;--chip:rgba(247,147,26,.13)}',
    '@media (prefers-color-scheme:light){:root{--bg:#eceef2;--card:#fff;--border:#e0e3e9;--text:#191c20;--muted:#616877;--accent:#c9760a;--chip:rgba(201,118,10,.10)}}',
    '*{box-sizing:border-box}',
    'body{margin:0;background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;line-height:1.5;-webkit-font-smoothing:antialiased;padding:32px 16px}',
    '.wrap{max-width:600px;margin:0 auto;display:flex;flex-direction:column;gap:12px}',
    '.caption{font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);font-weight:600}',
    '.card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:18px 18px 20px}',
    '.head{display:flex;align-items:center;gap:12px;margin-bottom:14px}',
    '.avatar{width:46px;height:46px;border-radius:50%;object-fit:cover;background:#fff;flex:none}',
    '.who{min-width:0}',
    '.name{font-weight:700;font-size:15px;display:flex;align-items:center;gap:6px}',
    '.badge{color:var(--accent);font-size:12px}',
    '.handle{font-size:13px;color:var(--muted);font-variant-numeric:tabular-nums;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
    '.body{font-size:15.5px;word-wrap:break-word}',
    '.ln{white-space:pre-wrap}',
    '.ln.div{color:var(--muted);letter-spacing:.15em}',
    '.sp{height:.72em}',
    '.body a{color:var(--accent);text-decoration:none;word-break:break-all}',
    '.body a:hover{text-decoration:underline}',
    '.tag{color:var(--accent);font-weight:600}',
    '.shot{display:block;margin:10px 0 4px}',
    '.shot img{display:block;width:100%;max-width:300px;max-height:600px;object-fit:contain;border:1px solid var(--border);border-radius:14px;background:#000}',
    '.legend{font-size:12px;color:var(--muted);text-align:center;padding:0 8px}',
    '</style></head><body>',
    '<div class="wrap">',
    '<div class="caption">Nostr preview · how this note posts · v' + esc(version) + '</div>',
    '<article class="card">',
    '<header class="head"><img class="avatar" src="../../public/icon.png" alt="">',
    '<div class="who"><div class="name">EINUNDZWANZIG <span class="badge">✔</span></div>',
    '<div class="handle">' + esc(npub) + ' · now · via TWENTY ONE Companion</div></div></header>',
    '<div class="body">',
    body,
    '</div></article>',
    '<div class="legend">Screenshots load live from blossom.einundzwanzig.space — exactly the files that post to Nostr.</div>',
    '</div></body></html>',
  ].join('\n')
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
const SHORT = args.includes('--short')
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
  if (!text || text.length > 1500) {
    console.error('GATE FAIL (text): ' + JSON.stringify({ empty: !text, len: text.length, max: 1500 }))
    process.exit(1)
  }

  // 2) Build the content in the fixed layout (release text + download channels + hashtags).
  const t = L10N
  const header = SHORT ? t.headerShort(version) : t.header(version)
  const downloads = [
    DIV,
    t.downloadsIntro,
    '',
    t.zapstore,
    t.obtainium,
    t.github,
    t.source,
  ].join('\n')
  const hashtags = TOPICS.map(topic => '#' + topic).join(' ')
  // --short (hotfix): header + changes + a single GitHub repo link. No download block,
  // no hashtags in the body (topic t-tags stay in the event tags below for discovery).
  const content = SHORT
    ? `${header}\n\n${text}\n\n🐙 ${REPO}`
    : `${header}\n\n${text}\n\n${downloads}\n\n${hashtags}`

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
    const htmlRel = path.join('dist', 'v' + version, 'announce-preview.html')
    fs.writeFileSync(path.join(ROOT, htmlRel), buildPreviewHtml(content, version))
    console.log('🖼  HTML preview → ' + htmlRel + '  (open in a browser to eyeball the post)')
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
