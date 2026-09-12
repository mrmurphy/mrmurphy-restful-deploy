#!/usr/bin/env bash
#
# Build the distributable ZIP — the one you upload by hand, or over SFTP.
#
#   ./build-zip.sh        ->  dist/mrmurphy-restful-deploy-<version>.zip
#
# It is built from git (HEAD), not from the working tree, so the archive is
# exactly what was committed and reviewed. The dev-only tests/ directory and the
# .gitignore are left out: tests/ holds throwaway fixture packages, and its
# README says to exclude it from anything you distribute.
#
# Version comes from the plugin header, so the filename cannot drift from the
# plugin.

set -euo pipefail

cd "$(dirname "$0")"

slug=mrmurphy-restful-deploy
version=$(sed -n 's/^ \* Version: *\(.*\)$/\1/p' "${slug}.php" | head -1)

if [ -z "$version" ]; then
	echo "Could not read Version from ${slug}.php" >&2
	exit 1
fi

# The archive comes from HEAD, but the version above comes from the working tree.
# With uncommitted changes those disagree: you get a ZIP whose name promises one
# version and whose contents are the last commit. Refuse instead of mislabelling.
if ! git diff --quiet || ! git diff --cached --quiet; then
	echo "Working tree has uncommitted changes, and git archive builds HEAD —" >&2
	echo "the archive would not be the code you are looking at. Commit first." >&2
	exit 1
fi

out="dist/${slug}-${version}.zip"

mkdir -p dist
rm -f "$out"

# --prefix puts everything under the single top-level folder that WordPress
# requires; a zip of loose files is not an installable plugin.
# What ships: the plugin, its classes, the brief the settings screen reads, the
# README, and the licence. What does not: the test harness, the packaging script
# and the repo's ignore file.
git archive --format=zip --prefix="${slug}/" -o "$out" HEAD -- . \
	':(exclude)tests' \
	':(exclude)build-zip.sh' \
	':(exclude).gitignore'

# Belt and braces: the version inside the archive must be the one in its name.
archived_version=$(unzip -p "$out" "${slug}/${slug}.php" | sed -n 's/^ \* Version: *\(.*\)$/\1/p' | head -1)
if [ "$archived_version" != "$version" ]; then
	echo "Archive holds version ${archived_version:-unknown} but is named ${version}. Refusing." >&2
	rm -f "$out"
	exit 1
fi

echo "Built ${out} (version ${version})"
echo
unzip -l "$out"
