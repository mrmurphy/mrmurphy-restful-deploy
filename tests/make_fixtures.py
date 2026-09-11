#!/usr/bin/env python3
"""Build fixture zips for the MrMurphy Package API test.

Only the fixtures/ subdirectory is wiped, so this script cannot delete the
test harness sitting next to it.
"""
import os
import shutil
import zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.join(HERE, "fixtures")
if os.path.isdir(ROOT):
    shutil.rmtree(ROOT)
os.makedirs(ROOT)

PLUGIN_MAIN = """<?php
/**
 * Plugin Name: MrMurphy Test Package
 * Description: Fixture plugin used to exercise the package API. Does nothing.
 * Version: 0.0.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'MRMURPHY_TEST_PACKAGE_LOADED', '0.0.1' );
"""

PLUGIN_HELPER = """<?php
defined( 'ABSPATH' ) || exit;
"""

THEME_STYLE = """/*
Theme Name: MrMurphy Test Theme
Description: Fixture theme used to exercise the package API.
Version: 0.0.1
Requires at least: 6.0
Requires PHP: 7.4
*/
"""

THEME_INDEX = """<?php
defined( 'ABSPATH' ) || exit;
"""


def write_plugin_zip(path):
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("__MACOSX/._junk", b"junk")  # must be skipped, not counted
        z.writestr("mrmurphy-test-package/mrmurphy-test-package.php", PLUGIN_MAIN)
        z.writestr("mrmurphy-test-package/inc/helper.php", PLUGIN_HELPER)


def write_theme_zip(path):
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("mrmurphy-test-theme/style.css", THEME_STYLE)
        z.writestr("mrmurphy-test-theme/index.php", THEME_INDEX)


def write_flat_zip(path):
    """No top level folder: every entry sits at the archive root."""
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("mrmurphy-flat.php", PLUGIN_MAIN)
        z.writestr("readme.txt", "flat zip, no folder\n")


def write_traversal_zip(path):
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("mrmurphy-evil/mrmurphy-evil.php", PLUGIN_MAIN)
        z.writestr("../escaped.php", "<?php // must never be written\n")


def write_junk_zip(path):
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("mrmurphy-junk/readme.txt", "not a plugin or a theme\n")


def write_single_file_zip(path):
    """One file at the archive root: a lone file, not a folder."""
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("arch-flat.php", PLUGIN_MAIN)


def write_deep_header_zip(path):
    """Plugin header only at depth 3: inspect_zip accepts it, check_package does not."""
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("arch-deep/inc/deep.php", PLUGIN_MAIN)
        z.writestr("arch-deep/inc/other.php", PLUGIN_HELPER)


def write_self_name_zip(path):
    """Top level folder named after the API plugin itself."""
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("mrmurphy-restful-deploy/replacement.php", PLUGIN_MAIN)


def write_hostile_name_zip(path, folder):
    """Archive whose single top level folder carries a hostile name."""
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr(folder + "/style.css", THEME_STYLE)
        z.writestr(folder + "/index.php", THEME_INDEX)


def write_symlink_zip(path):
    """Theme archive whose folder name matches a symlinked theme directory.

    The single top level folder is what the plugin looks for; the caller picks
    the name, which is how the symlink-overwrite attack is aimed.
    """
    info = zipfile.ZipInfo("symlink-target/style.css")
    info.external_attr = 0o120777 << 16  # symlink flag, to prove it is ignored
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr(info, THEME_STYLE)
        z.writestr("symlink-target/index.php", THEME_INDEX)


write_plugin_zip(os.path.join(ROOT, "mrmurphy-test-package.zip"))
write_theme_zip(os.path.join(ROOT, "mrmurphy-test-theme.zip"))
write_flat_zip(os.path.join(ROOT, "flat.zip"))
write_traversal_zip(os.path.join(ROOT, "traversal.zip"))
write_junk_zip(os.path.join(ROOT, "junk.zip"))
write_symlink_zip(os.path.join(ROOT, "symlink-name.zip"))
write_single_file_zip(os.path.join(ROOT, "single-file.zip"))
write_deep_header_zip(os.path.join(ROOT, "deep-header.zip"))
write_self_name_zip(os.path.join(ROOT, "self-name.zip"))
write_hostile_name_zip(os.path.join(ROOT, "dotdot-name.zip"), "..")
write_hostile_name_zip(os.path.join(ROOT, "triple-dot-name.zip"), "...")

for name in sorted(os.listdir(ROOT)):
    full = os.path.join(ROOT, name)
    if not name.endswith(".zip"):
        continue
    print("%-28s %7d bytes" % (name, os.path.getsize(full)))
    with zipfile.ZipFile(full) as z:
        for info in z.infolist():
            print("      %-52s %6d -> %6d" % (info.filename, info.compress_size, info.file_size))
