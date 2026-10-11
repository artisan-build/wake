#!/usr/bin/env bash
set -euo pipefail

version="1.5.6"
root="$(cd "$(dirname "$0")/.." && pwd)"
mkdir -p "$root/bin" "$root/var/duckdb-extensions"

case "$(uname -s)-$(uname -m)" in
  Linux-aarch64|Linux-arm64) asset="duckdb_cli-linux-arm64.zip" ;;
  Linux-x86_64) asset="duckdb_cli-linux-amd64.zip" ;;
  Darwin-arm64) asset="duckdb_cli-osx-arm64.zip" ;;
  Darwin-x86_64) asset="duckdb_cli-osx-amd64.zip" ;;
  *) echo "unsupported platform" >&2; exit 1 ;;
esac

archive="$(mktemp "${TMPDIR:-/tmp}/wake-duckdb.XXXXXX.zip")"
trap 'rm -f "$archive"' EXIT
curl -fsSL "https://github.com/duckdb/duckdb/releases/download/v${version}/${asset}" -o "$archive"
unzip -qo "$archive" -d "$root/bin"
chmod 0755 "$root/bin/duckdb"
"$root/bin/duckdb" -unsigned -c "SET extension_directory='$root/var/duckdb-extensions'; INSTALL ducklake; INSTALL postgres; INSTALL httpfs;"
