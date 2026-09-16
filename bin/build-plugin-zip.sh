#!/usr/bin/env bash
#
# Build the installable plugin archive.
#
#   bin/build-plugin-zip.sh [output-directory]
#
# Produces idta-partial.zip, which unpacks to a single idta-partial/ directory
# ready to drop into wp-content/plugins/ with no further steps.
#
# Far simpler than idta-pdf's equivalent because there is nothing to prune: no
# composer dependencies, no bundled fonts, no artwork. The only real job is
# keeping docs/ and the build script itself out of the archive — the design
# notes are working documents, not plugin files, and shipping them would put the
# schema and the endpoint's auth scheme inside a directory that is
# world-readable on many hosts.
#
# The bare filenames are listed alongside docs/ deliberately: they were at the
# plugin root before, and someone moving one back would otherwise ship it
# without noticing.

set -euo pipefail

plugin_dir="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
out_dir="${1:-$plugin_dir}"
work="$( mktemp -d )"
stage="$work/idta-partial"

trap 'rm -rf "$work"' EXIT

command -v zip >/dev/null || { echo "zip is required." >&2; exit 1; }
command -v php >/dev/null || { echo "php is required." >&2; exit 1; }

echo "Staging..."

rsync -a \
	--exclude '.git' \
	--exclude '.github' \
	--exclude '.gitignore' \
	--exclude 'bin/' \
	--exclude 'docs/' \
	--exclude 'DESIGN.md' \
	--exclude 'updated-design.md' \
	--exclude '*.zip' \
	--exclude '*.log' \
	--exclude '.DS_Store' \
	"$plugin_dir/" "$stage/"

# A syntax error in a shipped file is a white screen on activation, and this is
# the last point at which it costs nothing to notice.
echo "Checking syntax..."

while IFS= read -r -d '' file; do
	php -l "$file" >/dev/null || { echo "Syntax error in ${file#$stage/}" >&2; exit 1; }
done < <( find "$stage" -name '*.php' -print0 )

# The plugin header is what WordPress reads to list the plugin at all.
grep -q 'Plugin Name:' "$stage/idta-partial.php" || {
	echo "idta-partial.php has no plugin header." >&2
	exit 1
}

mkdir -p "$out_dir"
rm -f "$out_dir/idta-partial.zip"

( cd "$work" && zip -qr9 "$out_dir/idta-partial.zip" idta-partial )

echo
echo "Built $out_dir/idta-partial.zip"
du -sh "$out_dir/idta-partial.zip" | awk '{print "Archive:  " $1}'
du -sh "$stage" | awk '{print "Unpacked: " $1}'
