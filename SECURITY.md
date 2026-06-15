# Security Policy

TWENTY ONE Companion is the official app of the EINUNDZWANZIG community: it shows meetups,
events, courses and places from the [TWENTY ONE Portal](https://portal.einundzwanzig.space)
and optionally connects to the portal via Lightning or Nostr login (Sanctum token).
The app stores the portal API token in the device keystore (SecureStorage) and handles
deep links (`einundzwanzig://`, App Links on `portal.einundzwanzig.space`).

## Supported versions

| Version | Supported |
| ------- | --------- |
| Latest release | ✅ |
| `master` branch | ✅ |
| Older releases | ❌ |

Security fixes are provided only for the current release and the `master` branch.
Please always update to the latest version.

## Reporting a vulnerability

Please report vulnerabilities **confidentially** via GitHub's private vulnerability reporting:

1. Open the [Security → Advisories](https://github.com/HolgerHatGarKeineNode/twenty-one-companion/security/advisories/new) tab
2. Create a private report — **no public issues** for security problems.

Details stay confidential until a fix is available.

### What a report should contain

- A clear description of the vulnerability and its impact
- The affected area: deep-link/App-Link handling, token storage (SecureStorage),
  WebView/in-app browser, portal API client, or auth flow (Lightning/Nostr)
- App version (see Profile → About the app) and Android version
- Steps to reproduce
- If available: a suggested fix

### What you can expect

- Acknowledgement of receipt within 48 hours
- Resolution of critical issues within 90 days
- On request, public credit in the advisory (or anonymous)

## Scope

**In scope:**

- The source code of this repository and the official APKs from the GitHub releases
- Storage and handling of the portal API token (SecureStorage, logout/revoke)
- Deep-link/App-Link handling (`einundzwanzig://`, `https://portal.einundzwanzig.space/app/auth`)
- Auth flows (LNURL-auth, Nostr/NIP-55 callback) on the app side
- Opening external links (scheme allowlist) and rendering of portal content (Markdown rendering)

**Out of scope:**

- Vulnerabilities in the TWENTY ONE Portal itself (separate repository)
- Vulnerabilities in third-party dependencies without a concrete link to the app (please report upstream)
- Attacks that require a rooted/compromised device
- Denial-of-service against the portal or connected services

## Coordinated disclosure

We follow the coordinated-disclosure model: please give us a reasonable window to
fix the issue before any details are published. Anyone who follows this policy has
nothing to fear legally.
