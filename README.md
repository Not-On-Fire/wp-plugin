# NotOnFire for WordPress

The WordPress plugin behind [NotOnFire](https://notonfire.systems). It reports
fatal PHP errors, answers a signed, read-only endpoint with the installed
WordPress version and pending core, plugin and theme updates, and adds the
analytics tag to every page while analytics is switched on for the site.

## Installing on a site

1. In the NotOnFire dashboard, open the application's settings, enable
   WordPress and download the plugin. The ZIP comes with the site's connection
   already filled in.
2. In WordPress: Plugins → Add New → Upload Plugin, choose the ZIP, activate.

That is the whole install. Settings → NotOnFire shows the connection, the state
of error tracking and analytics, and a button that sends a test error.

Error tracking and analytics are switched on and off in the dashboard only. The
plugin picks a change up within 15 minutes, or immediately with "Sync now".

Sites still running the old must-use plugin delete
`wp-content/mu-plugins/000-wp-notonfire.php` before installing this one. There
is no automatic migration.

Multisite is not supported yet; activation is refused there.

## What activation changes

Activation writes `wp-content/mu-plugins/000-notonfire.php`. It holds no code of
its own: it requires `includes/class-notonfire-reporter.php` from this plugin's
directory, so the reporter runs before regular plugins and the active theme and
can report a broken theme. Updating the plugin therefore updates the reporter.

Deactivation removes the loader and monitoring stops; the settings are kept.
Deleting the plugin also removes its settings, its synchronized state and the
spool directory.

If `mu-plugins` is not writable, the plugin says so in wp-admin and loads the
reporter itself — later in the request, so errors raised while other plugins or
the theme load can be missed.

## Configuration

The connection (Server URL, Site ID, Site Token) can be changed on the settings
page, for a site that moves to a different NotOnFire application. Saving a
changed connection discards everything synchronized for the old one, including
undelivered events, and connects again right away.

The same values can be pinned in `wp-config.php` or as environment variables,
which makes the fields read-only:

```php
define( 'WP_NOTONFIRE_SERVER_URL', 'https://dashboard.example' );
define( 'WP_NOTONFIRE_SITE_ID', 12345 );
define( 'WP_NOTONFIRE_SITE_TOKEN', 'replace-with-the-site-token' );
```

`WP_NOTONFIRE_DSN` overrides the synchronized DSN, and `WP_NOTONFIRE_ENVIRONMENT`
(falling back to `WP_ENVIRONMENT_TYPE`) names the environment on every event.

The `notonfire_inject_analytics` filter decides per request whether the
analytics tag is printed. Previews and the customizer never get it; logged-in
editors are left out when "Don't track logged-in editors" is ticked.

## When the error-tracking backend is unreachable

A fatal error that cannot be delivered is kept in `wp-content/notonfire-spool/`
and sent by a later request, at most once a minute and five events at a time.
Only a 400, 413 or 422 drops an event; a network error, a 401 or any 5xx keeps
it. At most 50 events wait, none longer than 24 hours, and the spool is
discarded when the dashboard reports error tracking as disabled.

Files rather than the database, because the database may be what failed. The
directory is created `0700`, carries an `index.php` and a deny-all `.htaccess`,
and every event file starts with `<?php exit; ?>`. If `wp-content` is not
writable, events are lost and `WP_DEBUG` logs why.

## Updates

The plugin is not on WordPress.org. Its `Update URI` header makes WordPress ask
the plugin itself, which asks the signed `/api/v1/wordpress/plugin-update`
endpoint of the dashboard. A new version then shows up in Dashboard → Updates
like any other plugin update.

## Installing as a Composer package

The dashboard requires this repository directly, so that the exact version it
hands out and offers as an update is pinned in its `composer.lock`. It is not
on Packagist; a VCS repository entry is all a single consumer needs:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/Not-On-Fire/wp-plugin.git" }
    ]
}
```

The package deliberately declares no `autoload`. It carries the `notonfire/`
plugin directory, which is meant to run inside WordPress, never inside the
application that serves it — Composer must never load it.

## Releasing

`const VERSION` in `notonfire/includes/class-notonfire-config.php`, the
`Version:` header in `notonfire/notonfire.php` and the git tag are one number.
The dashboard reads the constant to decide whether a site is behind and which
version to offer as an update, and the plugin reports it back through its own
`User-Agent`, so a release that changes the code without changing the version
leaves every site looking current.
