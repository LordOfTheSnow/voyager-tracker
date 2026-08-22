# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/). This is the
project's first tagged version — 0.5.0 covers everything built so far, pre-1.0.

## [Unreleased]

## [1.1.0] - 2026-08-22

### Added

- New Horizons now shown on the home dashboard orrery as a third scale reference alongside
  Neptune — a plain, quiet marker with no pulse/contact halo or sun-line, unlike the Voyagers.
  This is the Voyager tracker, not a general probe tracker, so it gets nothing beyond the
  marker itself: no probe card, no detail page, no entry in the "Solar system — distances"
  modal.

### Changed

- Milestones page: the Voyager 1 / Voyager 2 filter buttons are now centered directly over the
  vertical timeline on desktop, instead of sitting left-aligned above it (still left-aligned on
  mobile, matching the timeline's own left-edge line there).
- Milestones page: Voyager 2's event date pills now use Voyager 2's own color instead of the
  generic accent outline previously shared with Voyager 1's dates.

### Fixed

- Planet/probe labels on the home orrery and distance modal were still unreadably small on real
  mobile phones despite 1.0.0's font-size bump. The actual cause was the SVG's fixed viewBox
  scaling every label down again as the canvas rendered narrower — a static font-size increase
  alone couldn't fully compensate. Labels are now counter-scaled against the canvas's actual
  rendered pixel size, not just against zoom, keeping them a constant on-screen size regardless
  of viewport width.

## [1.0.0] - 2026-08-21

First stable release — the last two placeholder nav pages (Milestones, About) are now built out,
so the site has no remaining stub content.

### Added

- Milestones page: a chronological timeline of both missions' major events (launch, planetary
  encounters, heliopause crossings, and more), filterable by probe, in a responsive layout that
  collapses from a two-column desktop timeline to a single-column mobile one.
- About page: mission background and project info, with a background-removed NASA Voyager probe
  photo, the app version, and a "by LordOfTheSnow" byline linking to the GitHub repo. Page copy
  lives in `config/about.php` (supports `<strong>`/`<em>` inline formatting) instead of the
  template, matching how `probes.php`/`milestones.php` already keep hand-edited content out of
  templates.
- Mission-milestone and About-page background image sources added to the Sources page citations.

### Changed

- Renamed the "Solar system — real distances" modal to "Solar system — distances" — the old name
  was misleading on the log-scale view, which isn't showing linearly-proportional (i.e. literally
  "real") distances.
- True linear scale view's Sun marker now scales directly with the zoom level (vanishingly small
  at zoom 1, growing to roughly its true size relative to Earth around zoom 10, where both are
  visible together), instead of either a fixed decorative size or a literal — and at any zoom
  level where Earth is also visible, all but invisible — astronomical scale.
- Nav order: About moved to the rightmost position, after Sources.

### Fixed

- Planet/probe labels and AU distance readouts on the home orrery, distance modal, and
  ecliptic-angle diagram were unreadable on narrow mobile screens: SVG text now uses explicit
  pixel font sizes (immune to the viewBox scaling that was shrinking them) with an additional
  size bump on mobile viewports.

## [0.7.0] - 2026-08-20

### Added

- AU and precise-mile figures alongside km on each probe's detail page distance cards
  (Distance from Sun/Earth).
- Mars back in the "Solar system — real distances" modal, with its own orbit ring and a
  distinct reddish dot, plus a note on which direction the bodies orbit (counter-clockwise,
  as viewed from ecliptic north).
- A lightweight "fetching the latest data" page shown instead of blocking the request when
  the 15-minute cache has just expired — the rare first visitor after a refresh window sees a
  spinner that swaps in the real page once the refresh completes, instead of a blank/spinning
  browser tab.
- Each probe's DSN band now shows its real deep-space frequency range (e.g. "X-band ·
  8,400–8,450 MHz", picked for the link's current uplink/downlink direction), and the data
  rate figure is labeled "bps = bits per second" to avoid bits/bytes ambiguity.

### Changed

- Country flags (DSN dish location) switched from Unicode flag emoji to self-hosted SVG
  icons — flag emoji don't render as pictures on every browser/OS combination (notably
  Chrome on Windows, which falls back to plain two-letter text); the SVGs always do.
- "Real distances" modal's orbit rings now stay a constant thin line at any zoom level
  (previously they thickened as you zoomed in), the Sun's marker is bigger and colored
  yellow instead of a barely-visible purple dot, and the canvas is centered instead of
  hugging the left edge on wide/short viewports.

### Deployment

- Docker image support: a `Dockerfile` (`php:8.4-cli-alpine`, PHP's built-in server, runs
  as non-root) and `compose.yaml`, published automatically to GHCR
  (`ghcr.io/lordofthesnow/voyager-tracker`) as a multi-arch (amd64/arm64) image on every
  tagged release. Not intended for production traffic — see the README's Docker section.
  `PORT`, `CACHE_TTL_SECONDS`, and `HTTP_TIMEOUT_SECONDS` are now overridable via
  environment variables.
- Releases are now cut automatically: pushing a version bump to `main` creates the matching
  git tag and GitHub Release on its own, which in turn drives both the Docker image publish
  above and the existing FTP release zip — no more pushing tags by hand.

## [0.6.0] - 2026-08-18

### Added

- Deep Space Network link card on each probe's detail page: live dish name and location (with
  a country flag), downlink/uplink direction, signal type, data rate, band, and signal power —
  each with a human-readable comparison (e.g. "350 times slower than a 56k dial-up modem",
  "100 billion times fainter than a typical WiFi signal"). Shows an explanatory message instead
  of an empty card when no dish is currently in contact.
- Country flag on the "signal active" badge showing which DSN complex (Goldstone, Canberra, or
  Madrid) is currently in contact.
- "Angle against the ecliptic" diagram replacing the old static Position & Heading schematic:
  an edge-on view of each probe's real trajectory angle against the ecliptic plane, live from
  Horizons, plus a partial heliopause arc.
- Light-day crossing projection: each probe's one-way light time now shows when it will next
  cross a whole light-day boundary, computed from real future JPL Horizons ephemeris samples
  (not a naive constant-speed guess, which breaks down against Earth's own orbital motion).

### Changed

- Instrument Health table rebuilt from NASA's official Voyager status page
  (science.nasa.gov), with a source link, replacing the previous illustrative/placeholder data.

## [0.5.0] - 2026-08-17

### Added

- Home dashboard orrery redrawn to real relative scale: Sun, Neptune (at its true live
  orbital position), and the heliopause boundary, alongside both Voyagers — canvas sizes
  itself to the live data each request instead of a fixed layout.
- Heliopause boundary ring on the "real distances" modal, in both log and linear scale views.
- AU distance and km/h/mph speed readouts alongside the existing km/s and billion-km figures
  on the home dashboard.
- Version number shown in the site header (this file's latest entry is the source of truth).

### Changed

- Probe cards on the home dashboard now show full detail (distance from Earth, days since
  launch, location, mission health, constellation) directly, without a "Show more" toggle.
- Home dashboard layout widened to make better use of desktop viewport space.

## [0.2.0] - 2026-08-16

### Added

- Precise (non-rounded) distance figures on the probe detail pages.

### Fixed

- Legibility issues in the "real distances" modal.

### Deployment

- Support for FTP-only hosts alongside the existing SSH + `git pull` deployment path.

## [0.1.0] - 2026-08-15

### Added

- Live position and status tracking for Voyager 1 and Voyager 2: distance from Sun/Earth,
  speed, one-way/round-trip light time, and DSN contact status.
- Per-probe detail pages with mission facts and static instrument-health and
  constellation-heading data.
- "Real distances" modal with pan/zoom log-scale and true-linear-scale views of the solar
  system.
- Outer planets (Jupiter through Pluto) shown at their live positions in the orrery.
