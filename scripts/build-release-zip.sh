#!/usr/bin/env bash
set -euo pipefail

plugin_slug="od-press-pilot"
version="${1:-}"

if [[ -z "${version}" ]]; then
	version="$(php -r 'if (preg_match("/Version:\\s*([^\\n]+)/", file_get_contents("od-press-pilot.php"), $m)) { echo trim($m[1]); }')"
fi

if [[ ! "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Release version must be in *.*.* format: ${version}" >&2
	exit 1
fi

plugin_version="$(php -r 'if (preg_match("/Version:\\s*([^\\n]+)/", file_get_contents("od-press-pilot.php"), $m)) { echo trim($m[1]); }')"
stable_tag="$(php -r 'if (preg_match("/Stable tag:\\s*([^\\n]+)/", file_get_contents("readme.txt"), $m)) { echo trim($m[1]); }')"

if [[ "${plugin_version}" != "${version}" ]]; then
	echo "Plugin header Version (${plugin_version}) must match release version (${version})." >&2
	exit 1
fi

if [[ "${stable_tag}" != "${version}" ]]; then
	echo "readme.txt Stable tag (${stable_tag}) must match release version (${version})." >&2
	exit 1
fi

release_root=".release"
package_dir="${release_root}/${plugin_slug}"
dist_dir="dist"
zip_path="${dist_dir}/${plugin_slug}-${version}.zip"

rm -rf "${release_root}" "${zip_path}"
mkdir -p "${package_dir}" "${dist_dir}"

rsync -a \
	--include='/build/***' \
	--include='/src/***' \
	--include='/vendor/***' \
	--include='/od-press-pilot.php' \
	--include='/readme.txt' \
	--include='/README.md' \
	--exclude='*' \
	./ "${package_dir}/"

if [[ ! -f "${package_dir}/build/index.js" ]]; then
	echo "Missing build/index.js. Run npm run build before packaging." >&2
	exit 1
fi

if [[ ! -f "${package_dir}/vendor/autoload.php" ]]; then
	echo "Missing vendor/autoload.php. Run composer install before packaging." >&2
	exit 1
fi

(
	cd "${release_root}"
	zip -qr "../${zip_path}" "${plugin_slug}"
)

echo "${zip_path}"
