#!/bin/bash
# Reproducible test-side migration for dossiq.
set -e
cd /tmp/lanes/ormig/dossiq
T=../tools
git checkout -- $(git diff --name-only tests | grep -v '^tests/Stubs/') 2>/dev/null || true
FILES=$(grep -rlE 'saveObject|find|deleteObject|patchObject|lockObject|unlockObject|saveObjects' tests --include=*.php | grep -v '^tests/Stubs' | grep -v LegacyObjectArgs)
php $T/rewrite-doubles.php --apply '--support=OCA\Dossiq\Tests\Support\LegacyObjectArgs' $FILES > ../logs/dossiq-doubles.tsv 2>&1
php $T/rewrite.php --apply --named-only '--recv=/./' $FILES > ../logs/dossiq-testcalls.tsv
F=tests/Unit/Service/WorkQueueServiceTest.php
php $T/rewrite.php --apply --method=saveObject --order=schema,object '--recv=/^\$this->objects$|^\$store$/' $F > /dev/null
for F in tests/Unit/Repair/ArmBezwaarArchiveTimersTest.php tests/Unit/Service/BeschikkingServiceTest.php tests/Unit/Service/Beschikking/BezwaarArchiveTriggerTest.php; do
  php $T/rewrite.php --apply --method=saveObject --order=register,schema,object '--recv=/^\$objects$|^\$this->objects$/' $F > /dev/null
  php $T/rewrite.php --apply --method=find --order=id,register,schema '--recv=/^\$objects$|^\$this->objects$/' $F > /dev/null
done
python3 $T/dossiq-manual.py
php $T/returnmap.php '--support=OCA\Dossiq\Tests\Support\LegacyObjectArgs' $(grep -rlE 'willReturnMap' tests/Unit tests/Support) > /dev/null
python3 $T/fiximports.py $(git diff --name-only tests | grep -v Stubs)
for f in $(git diff --name-only tests); do php -l $f >/dev/null 2>&1 || echo BAD $f; done
while read f m n; do python3 $T/withconv.py 'OCA\Dossiq\Tests\Support\LegacyObjectArgs' $f $m $n; done < $T/dossiq-with.txt
