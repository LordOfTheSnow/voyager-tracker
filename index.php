<?php

declare(strict_types=1);

/*
 * Fallback front controller for hosts where the document root can't be
 * pointed at public/ (fixed public_html on FTP-only shared hosting, for
 * example). Paired with the root .htaccess, which routes everything except
 * /assets/* here. See README "Deploying" for when this is needed.
 */
require __DIR__ . '/public/index.php';
