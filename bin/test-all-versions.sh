#!/usr/bin/env bash
# 在 Laravel 10、11、12、13 各跑一次套件測試（testbench 8／9／10／11）。
# 用法：bin/test-all-versions.sh [testbench 主版本 ...]，例如 bin/test-all-versions.sh 8 9
# 用 PHP_BIN 指定 PHP（預設 php）。Laravel 11、12 需要 PHP 8.2 以上，Laravel 13 需要 8.3 以上；
# 目前的 PHP 不夠新的版本會標示「略過」，請換 PHP 再跑一次。
set -euo pipefail
cd "$(dirname "$0")/.."

PHP_BIN="${PHP_BIN:-php}"
php_id=$("$PHP_BIN" -r 'echo PHP_VERSION_ID;')
versions=("$@")
[ ${#versions[@]} -eq 0 ] && versions=(8 9 10 11)

for testbench in "${versions[@]}"; do
    case "$testbench" in
        8) laravel=10; min=80100 ;;
        9) laravel=11; min=80200 ;;
        10) laravel=12; min=80200 ;;
        11) laravel=13; min=80300 ;;
        *) echo "不支援的 testbench 版本：$testbench"; exit 1 ;;
    esac
    if [ "$php_id" -lt "$min" ]; then
        echo "== Laravel ${laravel}：略過（目前 PHP $("$PHP_BIN" -r 'echo PHP_VERSION;') 太舊）"
        continue
    fi
    "$PHP_BIN" "$(command -v composer)" update --quiet --no-interaction -W --with "orchestra/testbench:^${testbench}.0"
    echo "== Laravel $("$PHP_BIN" "$(command -v composer)" show laravel/framework | grep '^versions' | sed 's/.*\* //')（PHP $("$PHP_BIN" -r 'echo PHP_VERSION;')）"
    "$PHP_BIN" vendor/bin/phpunit
done
