<?php

declare(strict_types=1);

// Static per-probe facts that don't come from any live source: launch date,
// heliopause crossing, constellation heading, and instrument health. There's
// no public API that decodes actual Voyager telemetry to instrument-level
// status, so instrument health is transcribed from NASA's own status table
// (https://science.nasa.gov/mission/voyager/where-are-voyager-1-and-voyager-2-now/,
// "Updated April 17, 2026") rather than fetched live — update it by hand
// whenever that page changes.

return [
    'voyager-1' => [
        'slug' => 'voyager-1',
        'name' => 'Voyager 1',
        'spkId' => '-31',
        'dsnSpacecraftId' => '-31',
        'launchDate' => '1977-09-05',
        'subtitleFact' => 'furthest human-made object from Earth',
        'dishSize' => '63m',
        'heading' => 'roughly toward the constellation Ophiuchus',
        'constellation' => 'Ophiuchus',
        'heliopauseCrossing' => '2012-08-25',
        'heliopauseCrossingLabel' => 'Aug 25, 2012',
        'powerSource' => 'RTG, decaying ~4W/yr',
        'instruments' => [
            ['name' => 'Cosmic Ray Subsystem (CRS)', 'active' => false, 'note' => 'power-saving since Feb 25, 2025'],
            ['name' => 'Low-Energy Charged Particles (LECP)', 'active' => false, 'note' => 'power-saving since Apr 17, 2026'],
            ['name' => 'Magnetometer (MAG)', 'active' => true],
            ['name' => 'Plasma Wave Subsystem (PWS)', 'active' => true],
            ['name' => 'Plasma Science (PLS)', 'active' => false, 'note' => 'degraded performance since Feb 1, 2007'],
            ['name' => 'Imaging Science Subsystem (ISS)', 'active' => false, 'note' => 'power-saving since Feb 14, 1990'],
            ['name' => 'Infrared Interferometer Spectrometer and Radiometer (IRIS)', 'active' => false, 'note' => 'power-saving since Jun 3, 1998'],
            ['name' => 'Photopolarimeter Subsystem (PPS)', 'active' => false, 'note' => 'degraded performance since Jan 29, 1980'],
            ['name' => 'Planetary Radio Astronomy (PRA)', 'active' => false, 'note' => 'power-saving since Jan 15, 2008'],
            ['name' => 'Ultraviolet Spectrometer (UVS)', 'active' => false, 'note' => 'power-saving since Apr 19, 2016'],
        ],
    ],
    'voyager-2' => [
        'slug' => 'voyager-2',
        'name' => 'Voyager 2',
        'spkId' => '-32',
        'dsnSpacecraftId' => '-32',
        'launchDate' => '1977-08-20',
        'subtitleFact' => 'only spacecraft to visit all four giant planets',
        'dishSize' => '34m',
        'heading' => 'roughly toward the constellation Pavo',
        'constellation' => 'Pavo',
        'heliopauseCrossing' => '2018-11-05',
        'heliopauseCrossingLabel' => 'Nov 5, 2018',
        'powerSource' => 'RTG, decaying ~4W/yr',
        'instruments' => [
            ['name' => 'Cosmic Ray Subsystem (CRS)', 'active' => true],
            ['name' => 'Low-Energy Charged Particles (LECP)', 'active' => false, 'note' => 'power-saving since Mar 24, 2025'],
            ['name' => 'Magnetometer (MAG)', 'active' => true],
            ['name' => 'Plasma Wave Subsystem (PWS)', 'active' => true],
            ['name' => 'Plasma Science (PLS)', 'active' => false, 'note' => 'power-saving since Sep 26, 2024'],
            ['name' => 'Imaging Science Subsystem (ISS)', 'active' => false, 'note' => 'power-saving since Oct 10, 1989'],
            ['name' => 'Infrared Interferometer Spectrometer and Radiometer (IRIS)', 'active' => false, 'note' => 'power-saving since Feb 1, 2007'],
            ['name' => 'Photopolarimeter Subsystem (PPS)', 'active' => false, 'note' => 'degraded performance since Apr 3, 1991'],
            ['name' => 'Planetary Radio Astronomy (PRA)', 'active' => false, 'note' => 'power-saving since Feb 21, 2008'],
            ['name' => 'Ultraviolet Spectrometer (UVS)', 'active' => false, 'note' => 'power-saving since Nov 12, 1998'],
        ],
    ],
];
