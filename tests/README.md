# MrMurphy Restful Deploy — tests

Dev-only harness. Nothing here runs on a normal request; `run-tests.php` bails
out unless WordPress is already loaded (`defined( 'ABSPATH' ) || exit`), and the
fixture builder is Python. Exclude `tests/` if you ever package the plugin for
distribution.

## Run

```bash
cd tests
python3 make_fixtures.py                     # (re)build the fixture zips

# These three need no fixture — a stock site with only the plugin installed.
# 1. out of the box: deployments are ON, no setup required
PKG_PHASE=default wp eval-file run-tests.php

# 2. the settings-screen switch: off closes the routes, on opens them again
PKG_PHASE=off wp eval-file run-tests.php

# 3. the throttle: what costs budget, what does not, and the 429 at the cap
PKG_PHASE=limits wp eval-file run-tests.php

# 4. the full install / activate / overwrite / uninstall / security flow
PKG_PHASE=enabled wp eval-file run-tests.php

# The two wp-config.php phases need the constant fixture in mu-plugins first.
# Use an absolute path: the plugin directory is usually a symlink into a repo
# elsewhere, and from a symlinked cwd a relative ../../.. lands in the physical
# parent — /Users/you/projects/mu-plugins, which does not exist. The copy then
# fails silently in a compound command, the constant is never defined, and the
# phases below abort or "fail" for reasons that have nothing to do with the code.
SITE=/Users/you/Studio/yoursite/wp-content
cp mu-plugin-consts.php "$SITE/mu-plugins/zz-mrmurphy-restful-deploy-test-consts.php"

# 4. the kill switch: a defined false beats the settings screen
MRMURPHY_RESTFUL_DEPLOY_TEST_FORCE_OFF=1 PKG_PHASE=forced_off wp eval-file run-tests.php

# 5. pinned on: a defined true ignores the settings screen
MRMURPHY_RESTFUL_DEPLOY_TEST_ENABLE=1 PKG_PHASE=forced_on wp eval-file run-tests.php

# then, when you are done:
rm "$SITE/mu-plugins/zz-mrmurphy-restful-deploy-test-consts.php"
```

Expected, in that order: `6 passed, 0 failed`, `10 passed, 0 failed`, `10 passed, 0 failed`,
`134 passed, 0 failed`, `7 passed, 0 failed`, `5 passed, 0 failed`.

Every phase clears its fixture plugin/theme and the plugin's options at the start, so runs are
repeatable and order-independent; the `enabled` phase clears them again at the end and asserts
the site was left as found (fixtures gone, active theme untouched, no options left), so a
finished run does not leave debris on the site — and the site ends up back in the default,
deployments-on state.

## How the phase switch works

`wp-config.php` constants cannot be defined twice, so the constant is injected for real:
`wp-content/mu-plugins` holds a throwaway drop-in that defines
`MRMURPHY_RESTFUL_DEPLOY_ENABLED` only when `MRMURPHY_RESTFUL_DEPLOY_TEST_ENABLE` (true) or
`MRMURPHY_RESTFUL_DEPLOY_TEST_FORCE_OFF` (`false`) is set in the environment. With neither set
it defines nothing — which is what phases 1–3 need, since "no constant" is the default branch.
The drop-in (`zz-mrmurphy-restful-deploy-test-consts.php`) is a test fixture: delete it after
testing, and never leave it on a site you care about, since anyone who can set an environment
variable could then pin the master switch.

Every phase opens with the options deleted (`…_log`, `…_refusals`, `…_enabled`) and the
per-hour throttle counters wiped, so runs are repeatable and order-independent.

## What the suite covers

- The default state (phase `default`), which is the interesting one now: with no constant and
  nothing stored, deployments are ON, `control_source()` says `default`, and `/inventory`
  answers 200 with no setup at all.
- The settings-screen switch (phase `off`): switching off closes the routes with
  `mrmurphy_restful_deploy_disabled` and installs nothing, the change is audited, the routes
  stay registered, and switching back on answers again.
- `wp-config.php` precedence, both directions: `forced_off` proves a defined `false` beats a
  screen that says on (the kill switch), `forced_on` proves a defined `true` beats a screen
  that says off.
- The throttle (phase `limits`): the shipped default is 30 operations per user per hour, the
  counter is keyed per user and hour, `dry_run` and reads spend nothing, one install costs
  exactly one, and the request past the cap gets `429 mrmurphy_restful_deploy_rate_limited`
  quoting the cap — while reads still answer. The counter is read with SQL, because the
  throttle writes it with SQL and `get_option()` would return a cached miss.
- Gate order: master switch → login → HTTPS → application password → capability.
- Capability denial (`rest_cannot_manage_plugins`) and the SSL gate.
- Archive validation: flat zips, `../` traversal entries, non-package zips,
  theme-in-plugin-endpoint, invalid base64, empty request — each must be refused
  with its own error code and must leave the filesystem untouched.
- Plugin install → activate → deactivate → duplicate (409 `folder_exists`) →
  overwrite, plus the overwrite gate.
- Theme install → switch → restore, and the "already active" path.
- Uninstall: an active plugin refused until `deactivate: true`, deletion over the query
  string as well as a JSON body, the self-delete refusal, a traversal attempt in `plugin`,
  the active-theme and missing-theme refusals, traversal in `stylesheet`, and that both
  successful and refused deletions land in the audit log.
- Security regressions, one per fixed finding: `.` / `..` / `...` / `.hidden` / `evil.`
  refused as theme stylesheets and inside archives, a lone file refused, `..` refused on
  activate/deactivate, self-overwrite refused, overwriting running code refused without
  `activate`, a failed install leaving nothing in `wp-content/upgrade/`, a symlinked theme
  directory surviving both overwrite and delete, overwrite refused by default, refusals
  counted rather than logged, and refused requests unable to evict the audit log.
- Inventory, audit log contents, and that every temp package is deleted.

The suite mutates the site it runs against (it installs a fixture plugin and
theme called `mrmurphy-test-package` / `mrmurphy-test-theme`, then restores the
previous theme). Never point it at mrmurphy.dev.
