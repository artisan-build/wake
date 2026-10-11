#!/usr/bin/env bash

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

for package in wake-contracts wake-client wake-server; do
    directory="${root}/packages/${package}"
    echo "== ${package} =="
    composer -d "${directory}" install --no-interaction --prefer-dist
    composer -d "${directory}" ready
done
