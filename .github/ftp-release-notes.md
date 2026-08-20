Pre-built package for FTP-only shared hosting (no shell or Composer access
needed on the server) -- `vendor/` is already installed.

Upload the *contents* of this zip into your host's document root as-is (this
`index.php` and `.htaccess` end up alongside `public/`, `src/`, etc.) -- see
the README's "Deploying (FTP-only shared hosting, no shell access)" section.

If your host lets you set the document root to `public/` instead, prefer the
plain `git clone`/`git pull` deployment path described in the README; this zip
is only needed for the fixed-document-root FTP case.
