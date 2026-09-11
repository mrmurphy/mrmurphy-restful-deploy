# MrMurphy Restful Deploy

Deploy WordPress plugins and themes over the REST API — upload, install, activate and
**uninstall** ZIP packages without SFTP or SSH access. Admin-only, authenticated with an
application password, with an audit log. Built to work well with scripts and AI agents.

MIT licensed. Repository: <https://github.com/mrmurphy/mrmurphy-restful-deploy>.

The plugin is **on by default**: activate it, create an Application Password, and deployments
work. Switch it off — and back on — from **Settings → Restful Deploy**, or define
`MRMURPHY_RESTFUL_DEPLOY_ENABLED` in `wp-config.php` as the hard kill switch.

## Why this exists

WordPress core already exposes part of this (verified against WP 7.1 on this install):

| Task | Core endpoint | Available? |
| --- | --- | --- |
| List plugins / activate / deactivate / delete | `GET PUT DELETE /wp/v2/plugins` | yes |
| Install a plugin **from the wordpress.org directory** by slug | `POST /wp/v2/plugins` | yes |
| Install a plugin from **your own ZIP** | — | **no** |
| List themes | `GET /wp/v2/themes` | yes (read-only) |
| Install a theme ZIP / switch theme / delete theme | — | **no** |

So: uploading arbitrary packages, switching themes and deleting themes need custom code.
That code is `remote code execution on purpose` — the whole design is about making it *only*
do that for a correctly authenticated, correctly privileged admin, and about leaving
evidence.

## Install

1. Copy this directory to `wp-content/plugins/mrmurphy-restful-deploy/`.
2. Activate it (Plugins screen, or `wp plugin activate mrmurphy-restful-deploy`).
3. Create an Application Password: **Users → Profile → Application Passwords**.

That is the whole install — deployments are on out of the box. Nothing has to be enabled
first, and no constant is required. To close the endpoints again, go to
**Settings → Restful Deploy** and switch them off; to close them from code, see below.

## Controlling the endpoints

**On by default, with two ways to change that.**

1. **Settings → Restful Deploy**, a `manage_options` screen (network plugins capability on
   multisite). One button, both directions: *Switch deployments off* / *Switch deployments on*.
   It also shows what the switch is doing, the gates, the endpoint list, the last ten audit
   entries and this hour's refusal counts. Both directions are written to the audit log, so
   "who turned this back on?" has an answer.
2. **`MRMURPHY_RESTFUL_DEPLOY_ENABLED` in `wp-config.php`, if it is defined at all.** This is
   the hard override for someone with code access: `false` kills the endpoints dead — it beats
   the settings screen, so nothing reachable from the admin (a stolen session, a rogue plugin)
   can bring them back. `true` pins them on so no UI can switch them off. Most sites never need
   either; `false` is there for the day you want the feature gone *now*.
3. Nothing else. **No route switches the endpoints on or off**, and nothing in the plugin
   changes the setting on its own — there is no timer, no window, no schedule.

Why it works this way: an Application Password *is* the credential, and the plugin trusts it —
it has to, that is the feature. What the plugin adds around it is blast-radius and evidence,
not a second gate: HTTPS only, an Application Password rather than a replayable nonce, the
capabilities the WordPress user already has, a per-user hourly throttle so a runaway script
cannot churn the site, and an audit log of every attempt. The kill switch is the escape hatch
for when trust is exactly what you have lost.

## Authentication

`Authorization: Basic base64(user:application-password)` — WordPress turns this into the
normal user context, so **capabilities still apply**:

- `/plugins*` needs `install_plugins` **and** `activate_plugins` (`delete_plugins` for
  `DELETE`).
- `/themes*` needs `install_themes` **and** `switch_themes` (`delete_themes` for `DELETE`).
- On multisite, network-admin capabilities as well, for read routes too.

A cookie + `X-WP-Nonce` session is **refused** by default: a nonce can be replayed by any
script that can read it, while an application password can be revoked on its own. The check
requires the *current* request to actually carry Basic credentials — core records the last
application password in a process-wide global, which alone would let a later cookie-only
request ride on an earlier request's login. Override with
`define( 'MRMURPHY_RESTFUL_DEPLOY_REQUIRE_APP_PASSWORD', false );` only if you must.

## Endpoints

Namespace `mrmurphy-restful-deploy/v1`.

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/inventory` | Installed plugins/themes, update status, active gates. |
| `GET` | `/log?limit=50` | Audit log (newest first) plus refusal counters. |
| `POST` | `/plugins` | Install a plugin ZIP, optionally activate. |
| `POST` | `/plugins/activate` | Activate an installed plugin. |
| `POST` | `/plugins/deactivate` | Deactivate an installed plugin. |
| `DELETE` | `/plugins` | Uninstall a plugin (optionally deactivating it first). |
| `POST` | `/themes` | Install a theme ZIP, optionally activate. |
| `POST` | `/themes/activate` | Switch the active theme. |
| `DELETE` | `/themes` | Uninstall a theme. |

Deletion needs `delete_plugins` / `delete_themes` (plus deactivation / theme-switching) and
is additionally refused for: an active plugin unless `deactivate: true` is passed, this API
plugin itself, the active theme, and the parent of the active theme. `DELETE /plugins` also
exists in core as `DELETE /wp/v2/plugins/<plugin>` (with the same active-plugin rule);
`DELETE /themes` has no core equivalent.

### `POST /plugins` and `POST /themes`

| Param | Type | Default | Meaning |
| --- | --- | --- | --- |
| `zip_base64` | string | — | The ZIP, base64 encoded (a `data:` URL is accepted). |
| `file` | multipart | — | Alternative to `zip_base64`: `-F file=@my-plugin.zip`. |
| `filename` | string | — | Cosmetic; used in messages and the temp file name. |
| `activate` | bool | `false` | Activate after installing. Also the confirmation for replacing running code. |
| `overwrite` | bool | `false` | Replace an existing directory. Opt-in twice: also needs `MRMURPHY_RESTFUL_DEPLOY_ALLOW_OVERWRITE` in wp-config.php. |
| `dry_run` | bool | `false` | Validate and describe the archive; install nothing. |
| `network_wide` | bool | `false` | Multisite: activate for the whole network. |

### `DELETE /plugins` and `DELETE /themes`

| Param | Type | Default | Meaning |
| --- | --- | --- | --- |
| `plugin` | string | required | Plugin file, e.g. `my-plugin/my-plugin.php`. |
| `deactivate` | bool | `false` | Deactivate the plugin first. Without it an active plugin is refused with `409`. |
| `stylesheet` | string | required | Theme directory name, for `DELETE /themes`. |

Parameters may arrive in a JSON body or in the query string. As in wp-admin, deleting a
plugin runs its own uninstall routine (`uninstall.php` / a registered uninstall hook), so
the plugin's data goes with it — that is the point of an uninstall, but it is not
recoverable from the ZIP alone.

### ZIP requirements

- Exactly **one top-level folder** (`zip -r my-plugin.zip my-plugin`) — a ZIP of loose
  files, or of a single file, is refused with `mrmurphy_restful_deploy_flat_archive`.
- A plugin must have a `Plugin Name:` header; a theme needs `style.css` with
  `Theme Name:`. A theme ZIP sent to `/plugins` (or vice versa) is refused.
- `__MACOSX/` entries are ignored; entries with `../`, `./`, a leading `/` or absolute
  paths are refused.
- Size caps: `MRMURPHY_RESTFUL_DEPLOY_MAX_BYTES` (32 MB default) for the upload,
  `MRMURPHY_RESTFUL_DEPLOY_MAX_UNCOMPRESSED_BYTES` (512 MB) for expansion, plus a
  compression-ratio sanity check. PHP's own limits apply *before* this plugin sees anything:
  `post_max_size` caps the whole POST body (multipart **or** JSON — base64 inflates the
  package by ~33% and counts against it), and `upload_max_filesize` caps a single uploaded
  file. When either is exceeded PHP truncates the request, so the endpoint reports a missing
  package; raise the PHP limits or shrink the package.

### Examples

```bash
SITE=https://mrmurphy.dev/wp-json/mrmurphy-restful-deploy/v1

# Base64 JSON body
curl -sS -u "murphy:xxxx xxxx xxxx xxxx xxxx xxxx" \
  -H 'Content-Type: application/json' \
  -d "$(python3 -c 'import base64,json,sys;print(json.dumps({"zip_base64":base64.b64encode(open("mrmurphy-theme.zip","rb").read()).decode(),"activate":True}))')" \
  "$SITE/themes"

# multipart upload, plugin, dry run first
curl -sS -u "murphy:xxxx xxxx xxxx xxxx xxxx xxxx" -F file=@my-plugin.zip -F dry_run=1 "$SITE/plugins"
curl -sS -u "murphy:xxxx xxxx xxxx xxxx xxxx xxxx" -F file=@my-plugin.zip -F activate=1 "$SITE/plugins"

# Uninstall (deactivating first)
curl -sS -u "murphy:xxxx xxxx xxxx xxxx xxxx xxxx" -X DELETE \
  "$SITE/plugins?plugin=my-plugin%2Fmy-plugin.php&deactivate=1"

# Inventory and audit log
curl -sS -u "murphy:xxxx xxxx xxxx xxxx xxxx xxxx" "$SITE/inventory"
curl -sS -u "murphy:xxxx xxxx xxxx xxxx xxxx xxxx" "$SITE/log?limit=20"
```

Successful install:

```json
{
  "ok": true,
  "dry_run": false,
  "package": {
    "detected_type": "plugin",
    "name": "My Plugin",
    "version": "1.2.3",
    "top_level_dir": "my-plugin",
    "main_file": "my-plugin/my-plugin.php",
    "entry_count": 42,
    "uncompressed_bytes": 310456
  },
  "installed": true,
  "plugin": "my-plugin/my-plugin.php",
  "activated": true,
  "duration_ms": 412,
  "audit": { "time": "2026-09-11T21:41:07+00:00", "action": "install_plugin", "status": "ok", "...": "..." },
  "activation_audit": { "action": "activate_plugin", "status": "ok", "...": "..." }
}
```

Installing from the wordpress.org directory is already core — no need to build the ZIP:

```bash
curl -sS -u "murphy:xxxx xxxx xxxx xxxx xxxx xxxx" \
  -H 'Content-Type: application/json' \
  -d '{"slug":"akismet","status":"active"}' \
  https://mrmurphy.dev/wp-json/wp/v2/plugins
```

## Security model and its limits

This API is remote code execution for a correctly authenticated, correctly privileged
admin. Everything below is about making "correctly" mean something, and about the ways it
can still go wrong.

**Guards on the destructive paths**

- Destinations are resolved before anything is written or deleted. A package directory that
  is a **symlink**, or that does not resolve to a direct child of `wp-content/plugins` or
  the theme root, is refused with `409 mrmurphy_restful_deploy_destination_is_symlink` /
  `_destination_outside_root`. This matters because `WP_Filesystem`'s recursive delete
  follows symlinks and core's own `delete_theme()` has no such check — on a site whose
  theme directory is a symlink into a repo, an unguarded overwrite or delete destroys the
  repo.
- Names must be single, safe path components: no `.`/`..`/`.hidden`, no leading dash, no
  trailing dot or space (Win32 strips those), no path separators, and no Windows reserved
  device names. The same validation is applied to archive top-level folders, plugin
  directories and theme stylesheets.
- Overwriting is **opt-in twice**: `MRMURPHY_RESTFUL_DEPLOY_ALLOW_OVERWRITE` has to be defined
  in `wp-config.php`, and each request still has to send `overwrite: true`. Overwriting a
  plugin or theme that is currently *running* additionally requires `activate: true`, so
  replacing live code is never a silent side effect.
- The API refuses to overwrite or delete itself, comparing resolved paths so a symlink
  alias cannot slip past.
- A failed install sweeps the extracted working directory out of `wp-content/upgrade/`,
  which is under the web root and would otherwise leave PHP reachable by URL with no install
  and no activation.
- `activate` / `deactivate` only accept paths that `get_plugins()` actually lists, and
  `DELETE /themes` refuses the active theme even when the name differs only in case (macOS
  and Windows resolve that), because core's `delete_theme()` would.

**Evidence**

- The install is written to the audit log **before** any package code runs, and a
  deactivation-before-delete is logged before the destructive call. A package whose own
  activation or uninstall hook deletes the log or calls `exit` can therefore still be
  traced to its install.
- Refusals are **counted, not logged** (bounded hourly
  counters, returned by `GET /log` under `refusals`). A caller who is already refused must
  not be able to grow — and therefore evict from — the capped audit log.
- Entries record the application-password UUID (`auth_uuid`) and user agent, so a leaked
  credential can be identified and revoked rather than guessed at.

**Known limits — read these**

- Anything installed through this API runs with the web server's privileges and can do
  whatever WordPress can, including tampering with the log from inside the request. The log
  records the install before that code runs; it cannot prevent what runs after.
- A stale directory can outlive its own package: `DELETE` refuses to remove a plugin whose
  main file is already missing (it is not a key in `get_plugins()`), so clean such leftovers
  up over SFTP.
- The hourly throttle is atomic (`gate_status()["rate_limit_mode"]` reports `sql`) where the
  database supports `INSERT ... ON DUPLICATE KEY UPDATE`; elsewhere it falls back to a
  best-effort counter and reports `transient`.
- Anonymous request failures are neither logged nor counted. Only failures from
  authenticated callers are counted.
- Package code runs in the request that installed it. A hook that calls `wp_die()` or echoes
  HTML can end a response early; core's own `/wp/v2/plugins` has the same exposure.
- Staged uploads live in the system temp directory, mode 0600, removed by a shutdown handler
  as well as by normal cleanup.

## Error codes

| Code | HTTP | Meaning |
| --- | --- | --- |
| `mrmurphy_restful_deploy_disabled` | 403 | Deployments switched off (or killed by the constant): switch them on under Settings → Restful Deploy. Anonymous callers get 404. |
| `mrmurphy_restful_deploy_not_authenticated` | 401 | No user context. |
| `mrmurphy_restful_deploy_requires_ssl` | 403 | Not HTTPS. |
| `mrmurphy_restful_deploy_requires_application_password` | 403 | Cookie/nonce session, or no Basic credentials on this request. |
| `mrmurphy_restful_deploy_requires_network_admin` | 403 | Multisite and not a network admin. |
| `mrmurphy_restful_deploy_rate_limited` | 429 | Hourly per-user operation budget spent. |
| `mrmurphy_restful_deploy_missing_package` | 400 | No `zip_base64` and no `file`. |
| `mrmurphy_restful_deploy_invalid_base64` | 400 | Bad base64 payload. |
| `mrmurphy_restful_deploy_incompatible_archive` | 400 | Not a readable ZIP. |
| `mrmurphy_restful_deploy_unsafe_archive_path` | 400 | Entry escapes the extraction root. |
| `mrmurphy_restful_deploy_flat_archive` | 400 | No single top-level folder, or a lone file. |
| `mrmurphy_restful_deploy_bad_top_level` | 400 | Unusable top-level folder name. |
| `mrmurphy_restful_deploy_unknown_package` | 400 | Neither plugin nor theme headers. |
| `mrmurphy_restful_deploy_wrong_package_type` | 400 | Theme sent to `/plugins` or vice versa. |
| `mrmurphy_restful_deploy_package_too_large` | 413 | Above the size cap. |
| `mrmurphy_restful_deploy_compression_ratio` | 413 | Looks like a decompression bomb. |
| `folder_exists` | 409 | Already installed; pass `overwrite: true` (and enable overwriting). |
| `mrmurphy_restful_deploy_overwrite_disabled` | 403 | Overwriting is not enabled in wp-config.php. |
| `mrmurphy_restful_deploy_overwrite_active_plugin` / `_active_theme` | 409 | Target is running; pass `activate: true` to confirm. |
| `mrmurphy_restful_deploy_destination_is_symlink` | 409 | Target is a symlink; refused so nothing outside wp-content is touched. |
| `mrmurphy_restful_deploy_destination_outside_root` | 409 | Target does not resolve to a direct child of the package root. |
| `mrmurphy_restful_deploy_bad_destination` | 400 | Unusable destination name. |
| `mrmurphy_restful_deploy_cannot_overwrite_self` | 403 | Archive targets this API plugin. |
| `rest_cannot_delete_active_plugin` | 409 | Active plugin; pass `deactivate: true`. |
| `mrmurphy_restful_deploy_cannot_delete_self` | 403 | Tried to delete this API plugin. |
| `mrmurphy_restful_deploy_theme_is_active` | 409 | Tried to delete the active theme. |
| `mrmurphy_restful_deploy_theme_has_active_child` | 409 | Theme is the parent of the active theme. |
| `mrmurphy_restful_deploy_invalid_plugin` / `_invalid_stylesheet` | 400 | Bad `plugin`/`stylesheet` name. |
| `mrmurphy_restful_deploy_delete_failed` / `_delete_incomplete` | 400 / 500 | WordPress refused, or files survived. |
| `mrmurphy_restful_deploy_plugin_not_found` / `_theme_not_found` | 404 | No such package. |
| `mrmurphy_restful_deploy_filesystem_unavailable` | 500 | PHP cannot write to the WP tree. |

## Configuration constants

All optional. **Every gate other than the master switch defaults to closed**, and the plugin
deploys out of the box.

```php
define( 'MRMURPHY_RESTFUL_DEPLOY_ENABLED', false );                 // master switch, default ON
define( 'MRMURPHY_RESTFUL_DEPLOY_REQUIRE_APP_PASSWORD', true );     // default true
define( 'MRMURPHY_RESTFUL_DEPLOY_REQUIRE_SSL', true );              // default true
define( 'MRMURPHY_RESTFUL_DEPLOY_ALLOW_OVERWRITE', true );          // default FALSE: opt in
define( 'MRMURPHY_RESTFUL_DEPLOY_MAX_BYTES', 32 * 1024 * 1024 );    // default 32 MB
define( 'MRMURPHY_RESTFUL_DEPLOY_MAX_UNCOMPRESSED_BYTES', 512 * 1024 * 1024 );
define( 'MRMURPHY_RESTFUL_DEPLOY_MAX_OPERATIONS_PER_HOUR', 12 );    // default 12
```

`MRMURPHY_RESTFUL_DEPLOY_ENABLED` is the exception to the "defined and not `false`" rule that
governs the others: it is the master switch, so what it is defined *as* matters. `false` is the
hard kill switch — it overrides the settings screen and the screen says so; `true` pins
deployments on; undefined leaves the settings screen in charge (and the screen defaults to on).

Every other constant is read as "defined and not the boolean `false`", so `1` behaves like
`true` and an undefined constant always takes the restrictive branch.

Filters: `mrmurphy_restful_deploy_enabled`, `..._app_password_required`, `..._ssl_required`,
`..._overwrite_allowed`, `..._max_bytes`, `..._max_uncompressed_bytes`,
`..._max_operations_per_hour`.

## Audit log

Every mutating attempt — success or failure — appends an entry (timestamp, action, target
user, auth method, application-password UUID, IP, user agent, message, context) to the
option `mrmurphy_restful_deploy_log`, capped at 200 entries, newest first, read back via
`GET /log`. The install entry is written before any package code runs. With `WP_DEBUG` on it
is mirrored to `error_log`. That log is the only way to answer "who put that file there"
after the fact.

Refusals are **not** written as entries: that would let a caller who is already refused flood
the capped log and evict real evidence. They are counted per hour instead and returned by
`GET /log` under `refusals`.

## Using it from an agent

`AGENT-INSTRUCTIONS.md` is written for exactly that: a short block to paste into an agent's
context, followed by the endpoint reference, copy-paste recipes, ZIP rules and a table of
every error code with the correct response to it. It ends with the house rules (dry-run
first, never overwrite unasked, one mutation at a time, stop and ask when the endpoints are
closed) and the things an agent must never do.

The short version of what an agent needs: the base URL, an Application Password, and
`GET /inventory` first — that response says whether the door is open (`gates.enabled`,
`gates.controlled_by`, `gates.armed_until`) and what is already installed.

## Tests

`tests/` holds a WP-CLI harness that drives the real REST dispatch path against a local
copy:

```bash
python3 tests/make_fixtures.py                                    # rebuild fixture ZIPs

PKG_PHASE=default     wp eval-file tests/run-tests.php            # 6 — out of the box it is ON
PKG_PHASE=off         wp eval-file tests/run-tests.php            # 10 — the off switch, both ways
PKG_PHASE=forced_off  wp eval-file tests/run-tests.php            # 7 — the kill switch wins
PKG_PHASE=forced_on   wp eval-file tests/run-tests.php            # 5 — the constant can pin it on
PKG_PHASE=enabled     wp eval-file tests/run-tests.php            # 134 — the whole API
```

`forced_off` and `forced_on` need the constant fixture copied into the target site's
`mu-plugins` (see `tests/README.md`); `default`, `off` and `enabled` run against a stock site
with nothing installed but the plugin.

The harness mutates whatever site it runs against (it installs a fixture plugin and theme
called `mrmurphy-test-package` / `mrmurphy-test-theme`, then restores the previous theme and
removes both fixtures and its own options at the end), but run it against a local copy, never
against a live site. See `tests/README.md`.
