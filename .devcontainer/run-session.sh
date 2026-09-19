#!/bin/sh
set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
project_dir=$(dirname "$script_dir")

cd "$project_dir"
exec docker compose -f "$script_dir/compose.yml" up
