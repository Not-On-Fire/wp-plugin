# NotOnFire WordPress Monitor

The must-use plugin behind [NotOnFire](https://notonfire.systems) WordPress
monitoring. It reports early fatal PHP errors and answers a signed, read-only
endpoint with the installed WordPress version and pending core, plugin and theme
updates.

## Installing on a site

Download the file from the application's settings page in the NotOnFire
dashboard — it comes with the site's credentials already written in — and upload
it unchanged to:

```text
wp-content/mu-plugins/000-wp-notonfire.php
```

Must-use plugins load before regular plugins and the active theme, which is what
lets the reporter survive a broken theme.

## When GlitchTip is unreachable

A fatal error that cannot be delivered is kept in `wp-content/notonfire-spool/`
and sent by a later request, at most once a minute and five events at a time.
Only a 400, 413 or 422 drops an event; a network error, a 401 or any 5xx keeps
it. At most 50 events wait, none longer than 24 hours, and the spool is
discarded when the dashboard reports error tracking as disabled.

Files rather than the database, because the database may be what failed. The
directory is created `0700`, carries an `index.php` and a deny-all `.htaccess`,
and every event file starts with `<?php exit; ?>`. If `wp-content` is not
writable, events are lost as before and `WP_DEBUG` logs why.

Configuring it by hand works too: define `WP_NOTONFIRE_SERVER_URL`,
`WP_NOTONFIRE_SITE_ID` and `WP_NOTONFIRE_SITE_TOKEN` in `wp-config.php`, or
export them as environment variables. The `define()` calls the dashboard writes
into the file are guarded, so those still win.

## Installing as a Composer package

The dashboard requires this repository directly, so that the exact version it
hands out is pinned in `composer.lock` rather than copied by hand. It is not on
Packagist; a VCS repository entry is all a single consumer needs:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/Not-On-Fire/wp-plugin.git" }
    ]
}
```

The package deliberately declares no `autoload`. It is a carrier for a single
file that is meant to run inside WordPress, never inside the application that
serves it — Composer must never load it.

## Releasing

`const VERSION` in the plugin, the `Version:` header above it, and the git tag
are one number. The dashboard reads the constant to decide whether a site is
behind, and reports it back through the plugin's own `User-Agent`, so a release
that changes the file without changing the version leaves every site looking
current.
