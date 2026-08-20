<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- livewire/flux (FLUXUI_FREE) - v2
- livewire/flux-pro (FLUXUI_PRO) - v2
- livewire/livewire (LIVEWIRE) - v4
- larastan/larastan (LARASTAN) - v3
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v5
- phpunit/phpunit (PHPUNIT) - v13
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== livewire/core rules ===

# Livewire

- Livewire allow to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>

# Project notes

## The accessibility harness — four criteria, and they are load-bearing

`tests/Browser/Accessibility/` holds four browser tests. Each one already found real defects here,
and each carries controls that exist because an earlier version of the measurement was silently
wrong.

| File | Criterion | What it asserts |
|---|---|---|
| `ContrastTest.php` | K1 · WCAG 1.4.3 | every visible text ≥ 4.5:1 (large/bold ≥ 3:1) against the **actually rendered** background |
| `KeyboardFocusTest.php` | K2 · WCAG 2.4.7 | every keyboard stop has a painted focus indicator |
| `AccessibleNameTest.php` | K3 · WCAG 4.1.2 | every interactive element has a non-empty accessible name |
| `TargetSizeTest.php` | K4 · WCAG 2.5.8 | every target ≥ 24×24, or far enough from its neighbours |

Run with `composer test:browser` (opt-in suite via `phpunit.browser.xml`, like the smoke tests).

## Measured in the app's default: mobile viewport, dark mode

`seite($pfad)` in `tests/Pest.php` does that. On a desktop viewport this would be a different
surface — the bottom navigation bar and its controls do not exist there.

**K4 is the exception and opens pages itself.** `on()->mobile()` brings its own device viewport and
beats a later `setViewportSize`; the run measured 369 px instead of the requested 320. K4 controls
the width itself and takes only the dark mode along. It only came out because the test asserts the
actual width against the requested one — keep that assertion.

## Two stylesheets, and a rule in the wrong one reaches nothing

`/profile` carries `#[Layout('group::einundzwanzig')]` and loads **only** `group-*.css`, no
`app-*.css` at all — two completely separate Vite entries. A focus rule added to `app.css` fixed
`/meetups` and `/courses` and left `/profile` untouched, although it was in the bundle. The comment
in `group.css` ("die Portal-Shell zieht ihr app.css") holds for the chat only.

The focus rules therefore live in **both** entries, deliberately duplicated and commented as such.
Their durable home is `einundzwanzig/group`'s own `theme.css` — move them there when that package is
next touched.

Also: the selectors are element-qualified (`a.pressable`, `summary.pressable`) on purpose. The
package theme ships its own `.pressable:focus-visible` (0-2-0) with the same invisible ring; a bare
`summary:focus-visible` is 0-1-1 and loses. Both rules are unlayered, so specificity really does
decide here.

## A ring is a box-shadow — prefer `outline`

The old rule gave Flux controls `outline-hidden ring-2 ring-accent`. **None of it arrived**: the
focused field's `box-shadow` was fully transparent, because a ring *is* a box-shadow and any later
`shadow-*` on the same element wins — and the Flux inputs carry `disabled:shadow-none
dark:shadow-none`. An `outline` sits on its own property and cannot be lost that way.

## Every one of these has a control. Do not delete them.

Each file starts with a test that injects a known-bad and a known-good element and asserts the
detector sorts both correctly. **A green run without a passing control is worthless.** The K3
controls check only the **injected** elements (marked `data-k3`) — an earlier version demanded the
whole page be clean, which is a control that depends on the thing it controls.

## Things that look like cleanup and are not

- **`FOKUS_LESEN` must contain no `//` comments** — a comment block makes `script()` return nothing
  and all cases report zero tab stops.
- **Tab presses go to `:root`, never `:focus`** — `Locator::press()` runs actionability checks and
  hangs. `.focus()` on `<html>` does not reset the tab order; measured.
- **K2 tabs through up to twice.** On `/events` a "retry" button only appears after the portal
  request has failed — the tab run was already past its position and reported 8 of 10 targets.
- **Colours are read through a 1×1 canvas** — Tailwind v4 emits `oklch()`, Flux `oklab()`.
- **K4's spacing exception is part of the criterion.** 44×44 (Apple HIG) is counted but does not
  fail the run: it is a design figure, not a legal threshold.

## The push notification text is measured, not assumed

`RelayPollWorker.postNotification` used to post `event.content` raw. A quote reply therefore
opened with ~100 characters of `nostr:nevent1…` and pushed the actual sentence below the fold
(user report with screenshot, 2026-08-19). `readableBody` now applies the web chat's rules:
drop the leading quote prefix (same `QUOTE_PREFIX` as `einundzwanzig-group`'s `js/polls.ts`),
replace remaining `nostr:` references with a word, shorten mentions, strip the markup that a
notification cannot render (`chatMarkup.ts stripInlineMarkup`).

**Where a reference ends is decided by the bech32 checksum, not by a length pattern.** Two
earlier versions used `[0-9a-z]+` and then `{60,1024}`; both ran past the entity, because a TLV
identifier carrying relay hints has no fixed length and the text behind it is made of the same
characters. Reproduced in review: a word glued to a `nevent1` was swallowed, and two adjacent
references became one match reaching into the second, leaving raw bech32 in the status bar — the
reported bug all over again. `kennungsEnde` therefore runs BIP-173's polymod as a rolling state
and stops at the first position where it reaches 1. That is the same disambiguation the web chat
gets from `nip19.decode` behind its `REF_TOKEN`. Consequence for the tests: every identifier in
`ReadableBodyTest.kt` must be a real one (`nostr-tools/nip19`) — an invented `"b".repeat(58)` is
not bech32 at all, the alphabet has no `b`.

**The word comes from the client**, through `push/sync` as `labels.quote`. Kotlin cannot reach
Laravel's catalogue and the app runs in eight languages; a hardcoded German word would reach
seven of them. Missing label → `…`, never the wrong word.

**It is really tested — on the JVM, not by a PHP replica.** `composer test:push-kotlin` copies
the plugin sources plus `packages/push/tests/ReadableBodyTest.kt` into the generated Android
project and runs Gradle on them. The test lives outside `nativephp/` because that whole tree is
generated and gitignored — a test placed there is gone after the next `native:install`. Needs
`php artisan native:install` to have run once; everything else about the worker (socket, NIP-42,
Amber, WorkManager) stays device-only.

## Playwright uses this machine's Chromium — never download one

`scripts/run-browser.sh` calls `scripts/link-host-chromium.sh`, which builds a symlink registry
under `~/.cache/ms-playwright` pointing at `/usr/bin/chromium`. **Never run
`npx playwright install`** — that cache is shared between all repos on this machine while their
Playwright versions are not, and an install prunes foreign revisions; after one such run here the
*entire* suite of a sister project was red. The script reads the revision from
`node_modules/playwright-core/browsers.json`; never hardcode it. (The older claim in
`run-browser.sh` that symlinks fail applied to an attempt that *did* hardcode it.)
