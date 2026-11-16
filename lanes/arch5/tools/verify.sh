#!/bin/bash
# verify.sh <clone> <outprefix> <fail-base>: phpstan error count + full unit suite failure set vs development
c=$1; o=$2; base=$3; cd "$c" || exit 2
./vendor/bin/phpstan analyse --memory-limit=4G --no-progress --error-format=raw > $o-phpstan.log 2>&1; echo "phpstan rc=$? errors: $(grep -c '^/' $o-phpstan.log)"
php -d memory_limit=2G vendor/bin/phpunit -c phpunit-unit.xml --no-coverage > $o-unit.log 2>&1; echo "phpunit rc=$?"; tail -1 $o-unit.log
grep -E "^[0-9]+\) " $o-unit.log | sed 's/^[0-9]*) //' | sort -u > $o-fails.txt
if diff <(sort -u $base) $o-fails.txt > $o-faildiff.txt; then echo SAME-FAILS; else echo FAILS-DIFFER; cat $o-faildiff.txt | head -30; fi
