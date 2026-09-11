# MrMurphy Restful Deploy — tests

Dev-only harness. Nothing here runs on a normal request; `run-tests.php` bails
out unless WordPress is already loaded (`defined( 'ABSPATH' ) || exit`), and the
fixture builder is Python. Exclude `tests/` if you ever package the plugin for
distribution.

## Run

```bash
# 1. copy the constant fixture into the TARGET SITE's mu-plugins.
#    Phases 3 and 4 need it; 1 and 2 must run without it.
#    Use an absolute path: the plugin directory is usually a symlink into a repo
#    elsewhere, and from a symlinked cwd a relative ../../.. lands in the physical
#    parent — /Users/you/projects/mu-plugins, which does not exist. The copy fails
#    silently in a compound command, the constant is never defined, and phases 3
#    and 4 then "fail" for reasons that have nothing to do with the plugin.
SITE=/Users/you/Studio/yoursite/wp-content
cp tests/mu-plugin-consts.php "$SITE/mu-plugins/zz-mrmurphy-restful-deploy-test-consts.php"

cd tests
python3 make_fixtures.py                     # (re)build the fixture zips

# 1. default state: the plugin is inert, nothing may install
PKG_PHASE=disabled wp eval-file run-tests.php

# 2. the settings-screen switch, with no constant defined
PKG_PHASE=toggle wp eval-file run-tests.php

# 3. wp-config.php is the boss: a defined false beats an armed screen
MRMURPHY_RESTFUL_DEPLOY_TEST_FORCE_OFF=1 PKG_PHASE=forced_off wp eval-file run-tests.php

# 4. enabled: full install / activate / validate / uninstall flow
MRMURPHY_RESTFUL_DEPLOY_TEST_ENABLE=1 PKG_PHASE=enabled wp eval-file run-tests.php

# then, when you are done:
rm "$SITE/mu-plugins/zz-mrmurphy-restful-deploy-test-consts.php"
```

Expected, in that order: `5 passed, 0 failed`, `16 passed, 0 failed`, `5 passed, 0 failed`,
`134 passed, 0 failed`.

Every phase clears its fixture plugin/theme and the plugin's options at the start, so runs are
repeatable and order-independent; the `enabled` phase clears them again at the end and asserts
the site was left as found (fixtures gone, active theme untouched, no options left), so a
finished run does not leave debris on the site.

## How the phase switch works

The constant gate is exercised for real: `wp-content/mu-plugins` holds a
throwaway drop-in that defines `MRMURPHY_RESTFUL_DEPLOY_ENABLED` only when
`MRMURPHY_RESTFUL_DEPLOY_TEST_ENABLE` (true) or `MRMURPHY_RESTFUL_DEPLOY_TEST_FORCE_OFF`
(`false`) is set in the environment. Phase 1 and 2 run with no constant at all —
which is what lets phase 2 test the settings-screen toggle, since that is the
branch a missing constant takes. That drop-in
(`zz-mrmurphy-restful-deploy-test-consts.php`) is a test fixture — delete it when you
are done testing, it is not part of the plugin.

Every phase opens with the options deleted (`…_log`, `…_refusals`, `…_enabled`,
`…_until`, `…_expiry_logged`), so runs are repeatable and order-independent.

## What the suite covers

- The master switch: routes exist but answer 403 while disabled.
- The settings-screen switch (phase `toggle`, no constant defined): closed by default, arming
  answers requests, the window expiry closes the endpoints on its own, disarm closes them, and
  arm/disarm/expiry each land in the audit log exactly once.
- `wp-config.php` precedence (phase `forced_off`): a defined `false` keeps the endpoints closed
  even while the settings screen says they are armed.
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
