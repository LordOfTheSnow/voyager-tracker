# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```
composer install                 # install dependencies
composer start                   # dev server at http://127.0.0.1:8000 (public/ as document root)
composer test                    # run full PHPUnit suite
vendor/bin/phpunit tests/FileCacheTest.php                      # single test file
vendor/bin/phpunit --filter testRefetchesAfterTtlExpires         # single test method
composer dump-autoload            # regenerate PSR-4 autoloader after adding new src/ classes
```

Use `127.0.0.1:8000`, not `localhost:8000`, when starting the dev server manually with `php -S` —
on this machine `php -S localhost:8000` binds IPv6-only (`[::1]`), and browsers trying IPv4 first
get connection refused. `composer start` already has this baked in.

## Architecture

No database, anywhere — this is deliberate, not a missing feature. See `README.md` for the full
rationale and deployment instructions (SSH + `git pull`, PHP 8.1+, no cron required).

**Data flow:** `App\VoyagerDataService` is the orchestrator. For each probe it calls
`App\Cache\FileCache::remember()`, which lazily refreshes a JSON file in `var/cache/` at most
once per `cacheTtlSeconds` (`config/app.php`, currently 15 min). The producer callback inside that
`remember()` call fetches from two independent live sources:

- `App\DataSource\HorizonsClient` — JPL Horizons (`ssd.jpl.nasa.gov/api/horizons.api`, official,
  no auth). Two separate requests per probe are required because Horizons only accepts one
  `CENTER` per call: Sun-centered gives distance + speed, Earth-centered gives distance + one-way
  light time. "Speed" is actually the Sun-centered range-rate (`deldot`), not a true 3D velocity
  vector — a deliberate approximation, accurate for both Voyagers since they're now moving
  essentially radially outward from the Sun.
- `App\DataSource\DsnClient` — NASA's official DSN Now feed (`eyes.nasa.gov/dsn/data/dsn.xml`,
  the same XML the eyes.nasa.gov visualization consumes). A probe frequently shows "not in
  contact" — that's real DSN dish-scheduling behavior, not an error state.

Both clients delegate actual response parsing to standalone, network-free classes
(`HorizonsResponseParser`, `DsnFeedParser`) specifically so the parsing logic — the part most
likely to silently break if either upstream changes its response shape — is unit-testable against
fixture text/XML without hitting the network. Keep that split when touching either client.

`HorizonsClient::fetchEarthLightTimeSeries()` is a third, much heavier Horizons call — a ~700-row,
20-year future ephemeris scan, cached separately for 24h via `FileCache::remember()`'s per-call TTL
override — used only to project when each probe's one-way light time will next cross a whole
light-day boundary (`App\Support\LightDayProjection`). That projection deliberately does *not*
extrapolate from a single current-speed reading the way "speed" above does: Earth's own orbital
motion makes a probe's *observed* recession rate (from Earth, not the Sun) swing between roughly
-8 and +41 km/s over a year, so a constant-rate guess can land months off. Real future samples are
scanned instead. This call is wrapped in its own try/catch in `VoyagerDataService` — a failure here
must not break the rest of the page, since it's supplementary, not core data.

On a failed refresh, `FileCache` falls back to the last good cached JSON with `stale: true` rather
than breaking the page; a hard error page (`templates/error.twig`) only renders if there's no
cache yet at all (e.g. the very first request after deploy). Per-probe static facts that have no
live source (launch date, heliopause crossing, constellation, instrument health) live in
`config/probes.php` — the instrument health table is intentionally static; there is no public API
that decodes Voyager telemetry to instrument-level status. It's transcribed by hand from NASA's own
status page (https://science.nasa.gov/mission/voyager/where-are-voyager-1-and-voyager-2-now/) rather
than fetched live, so it needs a manual re-check against that page whenever it's known to have
changed.

**Web layer:** Single front controller (`public/index.php`) wires Slim routes directly to
`VoyagerDataService` calls and Twig templates — no controller classes. `templates/layout.twig` is
the shared shell; `home.twig` and `detail.twig` (parametrized by probe slug, shared between both
Voyagers) extend it. `templates/stub.twig` serves the not-yet-designed Milestones/About/Sources
nav links.

**Frontend:** Alpine.js via CDN, no build step, no Node. The `<script defer>` for
`public/assets/js/app.js` (which registers `Alpine.data('homeOrrery', ...)` and
`Alpine.data('expandable', ...)` on the `alpine:init` event) **must load before** the Alpine core
CDN script tag in `layout.twig`. Alpine's CDN build auto-starts as soon as its own script runs; if
it loads first, it starts scanning the DOM before the component registrations exist and every
`x-data`/`x-show`/`x-text` expression throws `ReferenceError`.

The layout is responsive and has been verified to work cleanly on mobile as well as desktop
viewports — keep it that way. Any change touching `templates/*.twig` or
`public/assets/css/app.css` should be checked at a narrow (phone-width) viewport in addition to
desktop, not just eyeballed at whatever width the browser happens to be.
