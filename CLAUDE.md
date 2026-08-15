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

On a failed refresh, `FileCache` falls back to the last good cached JSON with `stale: true` rather
than breaking the page; a hard error page (`templates/error.twig`) only renders if there's no
cache yet at all (e.g. the very first request after deploy). Per-probe static facts that have no
live source (launch date, heliopause crossing, constellation, instrument health) live in
`config/probes.php` — the instrument health table is intentionally static; there is no public API
that decodes Voyager telemetry to instrument-level status, and the design itself labels that data
"illustrative."

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

**Known gap:** the SVG position diagrams (home dashboard orrery, per-probe "Position & heading")
are static schematic illustrations copied from the design handoff — fixed pixel coordinates, not
derived from live ephemeris data, unlike the numeric stats. Making them real is deferred/planned
future work (see project memory), not an oversight to silently fix.
