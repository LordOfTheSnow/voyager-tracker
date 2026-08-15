<?php

declare(strict_types=1);

// Static per-probe facts that don't come from any live source: launch date,
// heliopause crossing, constellation heading, and instrument health (the
// design handoff explicitly marks instrument status as illustrative, not
// live — there is no public telemetry-decode API to wire it to).

return [
    'voyager-1' => [
        'slug' => 'voyager-1',
        'name' => 'Voyager 1',
        'spkId' => '-31',
        'dsnSpacecraftId' => '-31',
        'launchDate' => '1977-09-05',
        'subtitleFact' => 'furthest human-made object from Earth',
        'dishSize' => '63m',
        'heading' => 'roughly toward the constellation Ophiuchus, above the plane of the planets',
        'constellation' => 'Ophiuchus',
        'heliopauseCrossing' => '2012-08-25',
        'heliopauseCrossingLabel' => 'Aug 25, 2012',
        'powerSource' => 'RTG, decaying ~4W/yr',
        'instruments' => [
            ['name' => 'Magnetometer (MAG)', 'active' => true],
            ['name' => 'Plasma Wave Subsystem (PWS)', 'active' => true],
            ['name' => 'Low-Energy Charged Particle (LECP)', 'active' => true],
            ['name' => 'Cosmic Ray Subsystem (CRS)', 'active' => true],
            ['name' => 'Imaging Science Subsystem', 'active' => false, 'note' => 'since 1990'],
            ['name' => 'Infrared Spectrometer (IRIS)', 'active' => false],
            ['name' => 'Ultraviolet Spectrometer (UVS)', 'active' => false],
            ['name' => 'Photopolarimeter (PPS)', 'active' => false],
            ['name' => 'Plasma Spectrometer (PLS)', 'active' => false, 'note' => 'failed 1980'],
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
        'heading' => 'roughly toward the constellation Pavo, below the plane of the planets',
        'constellation' => 'Pavo',
        'heliopauseCrossing' => '2018-11-05',
        'heliopauseCrossingLabel' => 'Nov 5, 2018',
        'powerSource' => 'RTG, decaying ~4W/yr',
        'instruments' => [
            ['name' => 'Magnetometer (MAG)', 'active' => true],
            ['name' => 'Plasma Wave Subsystem (PWS)', 'active' => true],
            ['name' => 'Low-Energy Charged Particle (LECP)', 'active' => true],
            ['name' => 'Cosmic Ray Subsystem (CRS)', 'active' => true],
            ['name' => 'Plasma Science (PLS)', 'active' => true],
            ['name' => 'Imaging Science Subsystem', 'active' => false, 'note' => 'since 1990s'],
            ['name' => 'Infrared Spectrometer (IRIS)', 'active' => false],
            ['name' => 'Ultraviolet Spectrometer (UVS)', 'active' => false, 'note' => '2023'],
            ['name' => 'Photopolarimeter (PPS)', 'active' => false, 'note' => 'failed 1978'],
        ],
    ],
];
