# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/). This is the
project's first tagged version — 0.5.0 covers everything built so far, pre-1.0.

## [Unreleased]

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
