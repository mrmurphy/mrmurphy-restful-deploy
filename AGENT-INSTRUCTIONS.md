# Agent instructions — deploying to a WordPress site with MrMurphy Restful Deploy

Everything an agent needs to deploy and manage plugins and themes on a site running this
plugin. The first block is meant to be **copied verbatim** into the agent's context; the rest
is the reference it can look things up in.

> The same text, with your site's base URL filled in, is on **Settings → Restful Deploy** in
> the WordPress admin: the block below in one field you can copy, and this whole reference in
> another.

---

## Paste this into your agent

```text
You can deploy and manage plugins and themes on a WordPress site through the
"MrMurphy Restful Deploy" REST API.

Base URL : https://<SITE>/wp-json/mrmurphy-restful-deploy/v1
Auth     : HTTP Basic — read from a gitignored ~/.netrc file (see below).
           Never put the password on the command line, in shell variables or in a script.

Authentication setup (do this once, before the first request):
- The credentials live in ~/.netrc, kept out of every repository with
  `git config --global core.excludesfile ~/.gitignore_global` plus `~/.netrc` in 
  that file (or a repo-local .gitignore entry if the working directory is inside a
  repository). The file must be readable only by you: `chmod 600 ~/.netrc`.
  If the working directory is inside a git repository, also add `netrc` and `.netrc`
  to that repository's `.gitignore` — the global exclude covers the home directory,
  a repo checkout is where the file would actually get committed.
- If ~/.netrc does not exist, create it with ONE entry for this site:

      machine <SITE-DOMAIN>
      login <WORDPRESS USER>
      password APP_TOKEN_HERE_PLEASE

  Replace <SITE-DOMAIN> with the host from the base URL below and <WORDPRESS USER>
  with the administrator's username. Leave APP_TOKEN_HERE_PLEASE exactly as it is,
  open the file in an editor, and ask the human to paste their real Application
  Password over that placeholder (they create one under Users → Profile →
  Application Passwords). Never ask them to paste the password into chat.
- After the human fills it in, confirm the placeholder is gone before the first
  request; a 401 mrmurphy_restful_deploy_not_authenticated usually means it is still there.
- curl picks the entry up automatically with `--netrc` (or `--netrc-file ~/.netrc`).

How to work:
- Validate every package with "dry_run": true before installing it. Dry runs are free.
- One mutating request at a time. Do not parallelise installs.
- After each mutating request, confirm with GET /inventory or GET /log.
- Pass "activate": true only when the change should go live immediately.
- You have 30 mutating operations per hour (repeatable ops: install, activate,
  deactivate, uninstall — one each). Reads and dry runs spend nothing, so keep
  checking your work. gates.max_operations_per_hour in /inventory says the limit.
- Never overwrite or uninstall anything without the human asking for it.
- If a request fails, read the error code and follow the recovery table below
  instead of retrying blindly.

If you get 403 mrmurphy_restful_deploy_disabled, deployments are switched off for this
site. You cannot switch them back on — no route does that. Ask the human to switch them
on under Settings → Restful Deploy, then continue.
```

---

## What this API is

It installs, activates, overwrites and uninstalls **plugin and theme ZIPs** over the
WordPress REST API, using capabilities the WordPress user already has — so an agent can
deploy code without SFTP, SSH or WP-CLI. Everything it does is audited. It is on by default,
and a human can switch it off (or a `wp-config.php` constant can kill it) at any time — so
check, and expect to be told no.

Base URL: `https://<SITE>/wp-json/mrmurphy-restful-deploy/v1`

Installing plugins from the wordpress.org directory by slug is **core WordPress**, not this
plugin: `POST https://<SITE>/wp-json/wp/v2/plugins` with `{"slug":"akismet","status":"active"}`.
Use this API for ZIPs you built yourself.

## Before the first request

| Check | How | If it fails |
| --- | --- | --- |
| Deployments switched on | any request; `403 mrmurphy_restful_deploy_disabled` means they are off | ask the human to switch them on under **Settings → Restful Deploy** |
| Application password | the human creates it in **Users → Profile → Application Passwords** | ask for one; a normal login password will not work |
| HTTPS | the site must be `https://` | the API refuses plain HTTP |
| Right host | call the site's own `/wp-json/...` | do not route through `public-api.wordpress.com`; this namespace is not proxied there |

Store the application password exactly as WordPress displays it, with or without the
spaces: core strips everything non-alphanumeric before comparing (`wp-includes/user.php`),
so `abcd EFGH 1234 ijkl MNOP 5678` and `abcdEFGH1234ijklMNOP5678` are the same credential.
If it contains spaces, quote it in `~/.netrc` — the shell never sees it there, but the
netrc parser needs the quotes.

## Endpoints

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/inventory` | Installed plugins + themes, update status, gates, on/off state |
| `GET` | `/log?limit=50` | Audit log (newest first) and refusal counters |
| `POST` | `/plugins` | Install a plugin ZIP, optionally activate |
| `POST` | `/plugins/activate` | Activate an installed plugin |
| `POST` | `/plugins/deactivate` | Deactivate an installed plugin |
| `DELETE` | `/plugins` | Uninstall a plugin (needs `deactivate: true` if it is active) |
| `POST` | `/themes` | Install a theme ZIP, optionally activate |
| `POST` | `/themes/activate` | Switch the active theme |
| `DELETE` | `/themes` | Uninstall a theme (refused for the active theme) |

`POST /plugins` and `POST /themes` accept:

| Field | Type | Default | Meaning |
| --- | --- | --- | --- |
| `zip_base64` | string | — | The ZIP, base64 encoded (a `data:` URL is fine) |
| `file` | multipart | — | Alternative: `-F file=@my-plugin.zip` |
| `filename` | string | — | Cosmetic only |
| `activate` | bool | `false` | Activate after installing; also confirms replacing running code |
| `overwrite` | bool | `false` | Replace an existing directory (the site may disable this entirely) |
| `dry_run` | bool | `false` | Validate and describe the archive, install nothing |
| `network_wide` | bool | `false` | Multisite: activate network-wide |

## Recipes

Set these once per session:

```bash
SITE=https://example.com/wp-json/mrmurphy-restful-deploy/v1
# credentials come from ~/.netrc — never set or export them here
```

**1. Look around first (always).**

```bash
curl -sS --netrc "$SITE/inventory" | python3 -m json.tool
```

Read `gates.enabled` (are deployments on?), `gates.controlled_by`,
`gates.max_operations_per_hour` (your budget), `plugins[]` (`plugin`, `version`, `status`,
`update_available`) and `themes[]`.

**2. Validate a package without touching the site.**

```bash
curl -sS --netrc -H 'Content-Type: application/json' \
  -d "$(python3 -c 'import base64,json;print(json.dumps({"zip_base64":base64.b64encode(open("my-plugin.zip","rb").read()).decode(),"dry_run":True}))')" \
  "$SITE/plugins"
```

`package.detected_type`, `name`, `version` and `top_level_dir` come back. If `detected_type`
is not what you expected, stop.

**3. Install and activate a plugin.**

```bash
curl -sS --netrc -F file=@my-plugin.zip -F activate=1 "$SITE/plugins"
```

Multipart is easier when the ZIP is on disk; base64 over JSON is easier when you generate the
payload in code. Both are equivalent.

**4. Install and activate a theme.**

```bash
curl -sS --netrc -F file=@my-theme.zip -F activate=1 "$SITE/themes"
```

Activating a theme switches the live site. Do it only when asked.

**5. Update a package that is already installed.**

```bash
curl -sS --netrc -F file=@my-plugin.zip -F overwrite=1 -F activate=1 "$SITE/plugins"
```

`overwrite: true` is how an upgrade happens, and it works out of the box. It is refused only
if the site has switched overwriting off, and an overwrite of a package that is currently
*running* is refused unless `activate: true` is also sent — that flag is the acknowledgement
that the replacement code goes live immediately.

**6. Deactivate, then uninstall.**

```bash
curl -sS --netrc -H 'Content-Type: application/json' -d '{"plugin":"my-plugin/my-plugin.php"}' \
  "$SITE/plugins/deactivate"

curl -sS --netrc -X DELETE \
  "$SITE/plugins?plugin=my-plugin%2Fmy-plugin.php&deactivate=1"
```

Uninstalling runs the package's own uninstall routine, which may delete its data — same as
the Plugins screen. Confirm with the human before uninstalling.

**7. Read the audit log when anything looks off.**

```bash
curl -sS --netrc "$SITE/log?limit=20" | python3 -m json.tool
```

`entries[]` records every attempt with the action, target, user, auth method, IP and result;
`refusals` counts failed requests by reason for the current hour.

## ZIP requirements

- **One top-level folder.** `zip -r my-plugin.zip my-plugin` — a ZIP of loose files, or of a
  single file, is refused (`mrmurphy_restful_deploy_flat_archive`).
- A plugin needs a `Plugin Name:` header, a theme needs `style.css` with `Theme Name:`.
- `__MACOSX/` noise is ignored; entries containing `../`, `./`, a leading `/` or an absolute
  path are refused.
- One site may cap uploads below PHP's limits — the error tells you the number.

## Errors and what to do

| Error code | HTTP | What it means / what to do |
| --- | --- | --- |
| `mrmurphy_restful_deploy_disabled` | 403 | Deployments are switched off. **Ask the human to switch them on.** Do not retry. |
| `mrmurphy_restful_deploy_not_authenticated` | 401 | Credentials missing or wrong. Ask for a fresh application password. |
| `mrmurphy_restful_deploy_requires_application_password` | 403 | You authenticated with a browser session, not Basic auth. |
| `mrmurphy_restful_deploy_requires_ssl` | 403 | Use `https://`. |
| `mrmurphy_restful_deploy_rate_limited` | 429 | Hourly budget spent (30 mutating operations per user per hour by default; reads and dry runs are free). Wait, or ask the human to raise the cap — do not retry in a loop. |
| `rest_cannot_manage_plugins` / `_themes` | 403 | This WordPress user cannot install/delete these. Ask for an administrator's app password. |
| `folder_exists` | 409 | Already installed. Use `overwrite: true` (if the site allows it). |
| `mrmurphy_restful_deploy_overwrite_disabled` | 403 | Overwriting is off site-wide. Ask the human; or uninstall then install. |
| `mrmurphy_restful_deploy_overwrite_active_plugin` / `_active_theme` | 409 | You are replacing running code: re-send with `activate: true` **only after the human agrees**. |
| `mrmurphy_restful_deploy_destination_is_symlink` | 409 | The target is a symlink (often a dev checkout). Do not work around it — deploy that package another way. |
| `mrmurphy_restful_deploy_flat_archive` | 400 | Re-zip so the archive contains exactly one folder. |
| `mrmurphy_restful_deploy_unknown_package` | 400 | No plugin/theme headers found — wrong archive? |
| `mrmurphy_restful_deploy_wrong_package_type` | 400 | Theme sent to `/plugins` or vice versa. |
| `mrmurphy_restful_deploy_package_too_large` / `_archive_too_large` | 413 | Package or its expansion is over the cap. |
| `rest_cannot_delete_active_plugin` | 409 | Add `deactivate: true` to the uninstall request. |
| `mrmurphy_restful_deploy_theme_is_active` | 409 | Switch to another theme first. |
| `mrmurphy_restful_deploy_cannot_delete_self` | 403 | The API will not uninstall itself. Use SFTP or the Plugins screen. |

## House rules

1. **`/inventory` first, every session.** It tells you whether the door is open, what is
   installed, and what will be overwritten.
2. **`dry_run` before install.** Cheap, and it catches a wrong archive before it lands.
3. **Never overwrite or uninstall unasked.** Both are destructive; the second may delete data.
4. **Sequential mutating calls.** The throttle is per user per hour (30 by default) and
   concurrent writes fight each other. Dry runs and reads are free, so a validate →
   install+activate → verify loop costs 1 operation per deploy.
5. **Verify, then report.** After a mutation, confirm with `/inventory`, and quote the audit
   entry it produced (`audit.time`, `audit.action`, `audit.target`).
6. **Stop and ask** when you meet `_disabled`, `overwrite_disabled`, or any request to delete
   something you did not install.
7. **Keep the source ZIP.** Rollback means overwriting with the previous ZIP; the API cannot
   undo a database change a plugin made when it ran.
8. **Credentials stay in `~/.netrc`.** Never echo it, `cat` it into a log, include it in a
   command you show the human, or copy it into any other file. If the placeholder
   `APP_TOKEN_HERE_PLEASE` is still in the file, the human has not filled it in yet — ask
   them to finish the step rather than working around it.

## What the agent must never do

- Try to switch deployments on or off. No route does that; it is a deliberate, human-only action.
- Install a package from a source nobody vouched for.
- Delete or deactivate a security plugin to "make things work".
- Retry a `429` or a `403` expecting a different answer.
- Put the Application Password anywhere but `~/.netrc`: no command-line `-u`, no shell
  variable, no script, no chat message. If the file is missing or its permissions are wrong
  (must be `600`), fix that before making requests.
