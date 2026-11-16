#!/bin/bash
# Mocks of the object service bound to other variable names.
T=$(dirname "$(readlink -f "$0")")
SUP='OCA\Decidiq\Tests\Support\LegacyObjectArgs'
FILES=$(grep -rlE "willReturnCallback" tests --include=*.php | grep -v Stubs)
php $T/rewrite-doubles.php --apply --cb-only "--support=$SUP" '--recv=/^\$facade$|^\$this->facade$|^\$failing$|^\$service$|^\$this->objects$/' $FILES > ../logs/decidiq-doubles3.tsv 2>&1
for f in $(git diff --name-only tests); do php -l $f >/dev/null 2>&1 || echo BAD $f; done
