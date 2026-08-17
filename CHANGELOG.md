# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/). This is the
project's first tagged version — 0.5.0 covers everything built so far, pre-1.0.

## [Unreleased]

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
