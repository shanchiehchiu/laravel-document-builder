#!/usr/bin/env bash
# 在 Laravel 10、11、12 各跑一次套件測試（testbench 8／9／10），最後切回最新版本。
# 用法：bin/test-all-versions.sh（需要 PHP 8.2 以上與 composer）
set -euo pipefail
cd "$(dirname "$0")/.."

for testbench in '^8.0' '^9.0' '^10.0'; do
    composer update --quiet --no-interaction -W --with "orchestra/testbench:${testbench}"
    echo "== $(composer show laravel/framework | grep '^versions' | sed 's/.*\* //')"
    vendor/bin/phpunit
done
