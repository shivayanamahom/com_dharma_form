#!/usr/bin/env bash
# Собирает установочный пакет Joomla: dist/pkg_dharma_form-<версия>.zip
# Внутри пакета каждое расширение лежит отдельным zip — так Joomla однозначно определяет тип пакета.
set -euo pipefail
cd "$(dirname "$0")"

version=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' pkg_dharma_form.xml | head -n1)
out="$PWD/dist/pkg_dharma_form-${version}.zip"
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT

mkdir -p dist
rm -f "$out"

EXTENSIONS=(com_dharmaform plg_system_dharmaform plg_task_dharmaform plg_dharmaformnotify_email plg_dharmaformnotify_telegram plg_dharmaformnotify_max)

for ext in "${EXTENSIONS[@]}"; do
  (cd "$ext" && zip -r -q "$stage/$ext.zip" . -x '*.DS_Store')
done

cp pkg_dharma_form.xml script.php LICENSE.txt "$stage/"
cp -R language "$stage/language"

(cd "$stage" && zip -r -q "$out" . -x '*.DS_Store')

echo "Готово: $out"
