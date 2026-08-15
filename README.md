# Voyager Distance Tracker

Live-ish distance/status tracker for Voyager 1 and Voyager 2, built for basic
shared PHP hosting. No database — see [Architecture](#architecture) below.

## Requirements

- PHP 8.1+ with `curl`, `simplexml`, and `json` extensions (all bundled by
  default on Arch/CachyOS's `php` package; on Debian-based hosts you may need
  `php-curl`/`php-xml` separately)
- [Composer](https://getcomposer.org/)

## Local development

```
composer install
composer start   # http://localhost:8000, built-in PHP dev server
composer test     # PHPUnit — fetch/cache/parsing logic only, no network calls
```

## Architecture

- **Data**: distance, speed, and one-way light time come from
  [JPL Horizons](https://ssd.jpl.nasa.gov/api/horizons.api) (official, no
  auth). Signal/contact status comes from NASA's official
  [DSN Now feed](https://eyes.nasa.gov/dsn/data/dsn.xml) (same XML the
  eyes.nasa.gov visualization uses). Instrument health is static — it's
  illustrative in the original design and there's no public telemetry-decode
  API to wire it to.
- **Caching**: no database. `App\Cache\FileCache` is a lazy TTL cache — one
  JSON file per probe in `var/cache/`, refreshed at most once every 15
  minutes (`config/app.php`). If a refresh fails, the last good cache is
  served with `stale: true` rather than breaking the page; a real error page
  only appears if there's no cache yet at all (e.g. first request ever).
- **Backend**: Slim Framework (routing) + Twig (templates — no PHP logic in
  views), autoloaded via Composer/PSR-4 (`App\` → `src/`).
- **Frontend**: Alpine.js via CDN for the zoom toggle and expand/collapse
  cards. No Node, no build step.

## Deploying (SSH, no root, cron optional but not required)

```
ssh you@host
cd /path/to/app
git clone <repo> .          # first time
# or: git pull               # subsequent deploys
composer install --no-dev --optimize-autoloader
```

Point the host's document root at `public/`. Make sure `var/cache/` is
writable by the PHP process (`FileCache` creates it automatically if
missing, but the parent `var/` directory must be writable).

No cron job is required — the cache refreshes lazily on request — but one
could be added later to pre-warm the cache if desired.
