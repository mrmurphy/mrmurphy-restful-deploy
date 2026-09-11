# MrMurphy Restful Deploy — tests

Dev-only harness. Nothing here runs on a normal request; `run-tests.php` bails
out unless WordPress is already loaded (`defined( 'ABSPATH' ) || exit`), and the
fixture builder is Python. Exclude `tests/` if you ever package the plugin for
distribution.

## Run

```bash
cd wp-content/plugins/mrmurphy-restful-deploy/tests
python3 make_fixtures.py                     # (re)build the fixture zips

# 1. default state: the plugin is inert, nothing may install
PKG_PHASE=disabled wp eval-file run-tests.php

# 2. enabled: full install / activate / validate flow
MRMURPHY_RESTFUL_DEPLOY_TEST_ENABLE=1 PKG_PHASE=enabled wp eval-file run-tests.php
```

Expected: `5 passed, 0 failed` then `127 passed, 0 failed`.

## How the phase switch works

The constant gate is exercised for real: `wp-content/mu-plugins` holds a
throwaway drop-in that defines `MRMURPHY_RESTFUL_DEPLOY_ENABLED` only when
`MRMURPHY_RESTFUL_DEPLOY_TEST_ENABLE` is set, so phase 1 sees the true default
(disabled) and phase 2 sees the wp-config.php equivalent. That drop-in
(`zz-mrmurphy-restful-deploy-test-consts.php`) is a test fixture — delete it when you
are done testing, it is not part of the plugin.

## What the suite covers

- The master switch: routes exist but answer 403 while disabled.
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
