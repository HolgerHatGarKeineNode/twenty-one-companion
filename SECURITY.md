# Security Policy

EINUNDZWANZIG Mobile ist die offizielle App der EINUNDZWANZIG-Community: Sie zeigt Meetups,
Termine, Kurse und Orte aus dem [EINUNDZWANZIG-Portal](https://portal.einundzwanzig.space)
und verbindet sich optional per Lightning- oder Nostr-Login (Sanctum-Token) mit dem Portal.
Die App speichert den Portal-API-Token im Geräte-Keystore (SecureStorage) und verarbeitet
Deep Links (`einundzwanzig://`, App Links auf `portal.einundzwanzig.space`).

## Unterstützte Versionen

| Version | Unterstützt |
| ------- | ----------- |
| Neuestes Release | ✅ |
| `master`-Branch | ✅ |
| Ältere Releases | ❌ |

Sicherheits-Fixes werden nur für das jeweils aktuelle Release und den `master`-Branch
bereitgestellt. Bitte aktualisiere immer auf die neueste Version.

## Schwachstelle melden

Bitte melde Schwachstellen **vertraulich** über GitHubs privates Vulnerability-Reporting:

1. Öffne den Tab [Security → Advisories](https://github.com/HolgerHatGarKeineNode/einundzwanzig-mobile-app/security/advisories/new)
2. Erstelle einen privaten Report — **keine öffentlichen Issues** für Sicherheitsprobleme.

Die Details bleiben vertraulich, bis ein Fix verfügbar ist.

### Was ein Report enthalten sollte

- Klare Beschreibung der Schwachstelle und ihrer Auswirkungen
- Betroffener Pfad: Deep-Link-/App-Link-Handling, Token-Speicherung (SecureStorage),
  WebView/In-App-Browser, Portal-API-Client oder Auth-Flow (Lightning/Nostr)
- App-Version (siehe Profil → Über die App) und Android-Version
- Schritte zur Reproduktion
- Falls vorhanden: Vorschlag für einen Fix

### Was du erwarten kannst

- Eingangsbestätigung innerhalb von 48 Stunden
- Behebung kritischer Probleme innerhalb von 90 Tagen
- Auf Wunsch öffentliche Anerkennung im Advisory (oder anonym)

## Geltungsbereich

**In Scope:**

- Quellcode dieses Repositories und die offiziellen APKs der GitHub-Releases
- Speicherung und Handhabung des Portal-API-Tokens (SecureStorage, Logout/Revoke)
- Deep-Link-/App-Link-Verarbeitung (`einundzwanzig://`, `https://portal.einundzwanzig.space/app/auth`)
- Auth-Flows (LNURL-auth, Nostr/NIP-55-Callback) auf App-Seite
- Öffnen externer Links (Scheme-Whitelist) und Darstellung von Portal-Inhalten (Markdown-Rendering)

**Out of Scope:**

- Schwachstellen im EINUNDZWANZIG-Portal selbst (separates Repository)
- Schwachstellen in Drittanbieter-Abhängigkeiten ohne konkreten Bezug zur App (bitte upstream melden)
- Angriffe, die ein gerootetes/kompromittiertes Gerät voraussetzen
- Denial-of-Service gegen das Portal oder verbundene Dienste

## Koordinierte Offenlegung

Wir folgen dem Modell der koordinierten Offenlegung: Bitte gib uns eine angemessene Frist
zur Behebung, bevor Details veröffentlicht werden. Wer sich an diese Richtlinie hält,
hat keine rechtlichen Konsequenzen zu befürchten.
