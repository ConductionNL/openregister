#!/bin/bash
# Reproducible test-side migration for an app: app-tests.sh <app> <TestsNamespace>
set -e
APP=$1; NS=$2
cd /tmp/lanes/ormig/$APP
T=../tools; SUP="$NS\\Support\\LegacyObjectArgs"
git checkout -- $(git diff --name-only tests) 2>/dev/null || true
STUBROOT=$STUBROOT SKIP_LIFECYCLE=$SKIP_LIFECYCLE $T/stubs.sh . > /dev/null
SD=${SUPPORTDIR:-tests/Support}
mkdir -p $SD
sed "s/__NS__/$(echo "$NS" | sed 's/\\/\\\\/g')\\\\Support/g" $T/LegacyObjectArgs.php.tpl > $SD/LegacyObjectArgs.php
sed "s/__NS__/$(echo "$NS" | sed 's/\\/\\\\/g')\\\\Support/g" $T/ReleaseObjectSignatures.php.tpl > $SD/ReleaseObjectSignatures.php
FILES=$(grep -rlE 'saveObject|find|deleteObject|patchObject|lockObject|unlockObject|saveObjects' tests --include=*.php | grep -viE '^tests/stubs/(OpenRegister/)?(Contract|Kernel)/' | grep -v 'Support/LegacyObjectArgs' | grep -v 'Support/ReleaseObjectSignatures' | grep -v '/node_modules/')
php $T/rewrite-doubles.php --apply "--support=$SUP" $FILES > ../logs/$APP-doubles.tsv 2>&1
php $T/rewrite-doubles.php --apply --cb-only "--support=$SUP" '--recv=/^\$object$|^\$objects$|^\$os$|^\$mock$|^\$this->mock$|^\$double$/' $FILES > ../logs/$APP-doubles2.tsv 2>&1
php $T/rewrite.php --apply --named-only '--recv=/./' $FILES > ../logs/$APP-testcalls.tsv
[ -f $T/$APP-manual.py ] && python3 $T/$APP-manual.py
php $T/returnmap.php "--support=$SUP" $(grep -rlE 'willReturnMap' tests --include=*.php | grep -v node_modules) > /dev/null || true
python3 $T/addmissing.py $(grep -rlP 'implements[^{]*\bObjectServiceInterface\b' tests --include=*.php | grep -viE 'tests/stubs/') 
python3 $T/reflparams.py $(grep -rlE 'LegacyObjectArgs::positional' tests --include=*.php)
[ -f $T/$APP-with.txt ] && while read f m n; do python3 $T/withconv.py "$SUP" $f $m $n; done < $T/$APP-with.txt
python3 $T/fiximports.py $(git diff --name-only tests | grep -v Stubs)
for f in $(git diff --name-only tests; git ls-files --others --exclude-standard tests); do php -l $f >/dev/null 2>&1 || echo BAD $f; done
[ -f $T/$APP-post.sh ] && bash $T/$APP-post.sh || true
