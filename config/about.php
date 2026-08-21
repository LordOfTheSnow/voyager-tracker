<?php

declare(strict_types=1);

// Copy for the About page. Edit this file to change the page's text -- kept out of
// templates/about.twig so it's a plain content edit, not a template edit, matching how
// probes.php and milestones.php keep hand-edited content out of the templates too.
// Paragraphs render unescaped, so inline HTML like <strong>...</strong> or <strong>...</strong>
// works for bold/italic.

return [
    'paragraphs' => [
        "Voyager Distance Tracker started as a test project to find out how to work with <strong>Claude AI</strong>. It started with generating wireframes and then designing page 
        mockups in <strong>Claude Design</strong> and then handing these over to <strong>Claude Code</strong>. During implementation we wired up live data from
        <strong>JPL Horizons</strong> and <strong>DSN</strong> data feeds and hand-tuned a fully responsive interface, built almost entirely through conversation
        rather than written line by line.",
        "It should be installable on any web server that can run PHP 8.2 or later, and it should work on any modern (mobile) browser.",
        "It's not affiliated with or endorsed by NASA or JPL — just a hobby project built on their public data.",
    ],
];
