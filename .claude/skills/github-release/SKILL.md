---
name: github-release
description: "Creates an Amber-quality GitHub release for the TWENTY ONE Companion app: verifies the artifacts in dist/v<version>/ (GPG signature, SHA256, apksigner), generates Amber-style release notes (summary, downloads, verification guide) and ALWAYS creates the release as a draft first via gh. Invoke with /github-release [version] — without an argument the version is derived from dist/ or the last build."
---

# Amber-quality GitHub release

Goal: a release page like [Amber's](https://github.com/greenart7c3/Amber/releases) —
signed artifacts, clean release notes with a summary, download options and a full
verification guide. **Always create it as a draft first**; the user reviews and
publishes it themselves.

## Fixed project data

- Repo: `HolgerHatGarKeineNode/twenty-one-companion`
- GPG fingerprint (manifest signature): `B2DD9D9969E61E617125346E6D5B01E06AA11B68`
  (block notation: `B2DD 9D99 69E6 1E61 7125  346E 6D5B 01E0 6AA1 1B68`)
- Android cert SHA256: `44:41:1E:20:A1:B4:3D:0F:66:CF:99:E1:23:8A:33:E7:E8:FD:92:48:F0:D0:D2:58:F5:E0:72:7C:FA:BF:0B:7C`
- Artifact convention: `dist/v<version>/twenty-one-companion-v<version>.apk`,
  `manifest-v<version>.txt`, `manifest-v<version>.txt.sig` (produced by `scripts/release.sh`)

## Procedure (strictly in this order)

### 1. Determine the version

Use argument `$1`, otherwise the newest `dist/v*` directory. Validate the `X.Y.Z` format.

### 2. Verify the artifacts — only create the release on a green result

All three files must be present in `dist/v<version>/`. Then verify:

```bash
gpg --verify dist/v<version>/manifest-v<version>.txt.sig dist/v<version>/manifest-v<version>.txt   # → "Good signature", fingerprint exactly B2DD…1B68
cd dist/v<version> && sha256sum -c manifest-v<version>.txt                                          # → OK
apksigner verify --print-certs <apk> | grep -i sha-256                                              # → 44411e20…0b7c (apksigner needs java/JBR in PATH)
```

If the `.sig` is missing or a check fails: **abort** and tell the user what is missing
(e.g. `SKIP_BUILD=1 ./scripts/release.sh` to sign). Never upload unsigned or
unverified artifacts.

### 3. Build a change summary

- If there is a previous release tag: use `git log <last-tag>..HEAD --oneline` as the source.
- First release: feature overview from `README.md`/`PLAN.md`.
- 3–8 concise bullet points, phrased for users (what's in it for them?),
  **in English** (all published release texts are English-only from v1.1.0 on —
  see memory `release-texte-englisch`), no commit hashes, no internal codenames.

### 4. Generate the release notes from the template

Write the notes file to `dist/v<version>/release-notes.md` (lives in dist/, is gitignored).
Template — replace placeholders, do NOT change the structure or the verification block:

```markdown
## What's new

<3–8 bullet points of the changes>

## Download

> There is no Play Store release. Official builds are available exclusively here on GitHub.

- Download `twenty-one-companion-v<version>.apk` below and **verify it** (see below)

## Verify the release

Full guide: [VERIFY_RELEASES.md](https://github.com/HolgerHatGarKeineNode/twenty-one-companion/blob/master/VERIFY_RELEASES.md)

**1. Import the signing key** (one-time):

​```bash
gpg --keyserver hkps://keys.openpgp.org --recv-keys B2DD9D9969E61E617125346E6D5B01E06AA11B68
​```

The fingerprint must read exactly: `B2DD 9D99 69E6 1E61 7125  346E 6D5B 01E0 6AA1 1B68` — otherwise **do not proceed**.

**2. Check the manifest signature:**

​```bash
gpg --verify manifest-v<version>.txt.sig manifest-v<version>.txt
​```

Expected: `gpg: Good signature from "fsociety.mkv@pm.me"`

**3. Compare the APK checksum:**

​```bash
sha256sum -c manifest-v<version>.txt
​```

**4. Check the Android certificate** (optional, [AppVerifier](https://github.com/soupslurpr/AppVerifier)/apksigner):

​```
space.einundzwanzig.mobile
44:41:1E:20:A1:B4:3D:0F:66:CF:99:E1:23:8A:33:E7:E8:FD:92:48:F0:D0:D2:58:F5:E0:72:7C:FA:BF:0B:7C
​```

## Security

Please report vulnerabilities confidentially: [SECURITY.md](https://github.com/HolgerHatGarKeineNode/twenty-one-companion/blob/master/SECURITY.md)
```

(Remove the `​` characters before the backticks in the template — they only prevent this
code block from closing too early.)

### 5. Create the draft release

First check whether the release commit is pushed (`git status` / `git log origin/master..HEAD`)
— the tag is set to the remote HEAD on publish; if there are unpushed commits, warn the
user but still create the draft.

```bash
gh release create v<version> \
  dist/v<version>/twenty-one-companion-v<version>.apk \
  dist/v<version>/manifest-v<version>.txt \
  dist/v<version>/manifest-v<version>.txt.sig \
  --draft \
  --title "v<version>" \
  --notes-file dist/v<version>/release-notes.md
```

If a release/draft already exists for the tag: ask instead of overwriting
(`gh release view v<version>`).

### 6. Final report to the user

- Give the draft URL (gh prints it) + note: review → "Publish release" manually.
- Summarize the verification results (signature ✓, hash ✓, cert ✓).
- **Never publish yourself, never commit/tag/push** — the user does that.
