#!/bin/bash
# usage: pcov.sh <tag>   targeted pcov run over .w01d/tests.txt with suite name "Unit Tests"
cd ~/sig-lanes/l51-or; T=$1
python3 - <<'P'
import re
files=open('.w01d/tests.txt').read().split()
x=open('phpunit.xml').read()
m=re.search(r'<testsuites>.*?</testsuites>',x,re.S)
ts='<testsuites><testsuite name="Unit Tests">'+''.join(f'<file>{f}</file>' for f in files)+'</testsuite></testsuites>'
y=re.sub(r'\s*<html [^>]*/>','',x[:m.start()]+ts+x[m.end():])
open('phpunit-w01d.xml','w').write(y)
P
mkdir -p coverage
docker run --rm --memory=3g -u 1000:1000 -v ~/sig-lanes/l51-or:/w -w /w l19b-pcov php -d pcov.enabled=1 -d pcov.directory=/w/lib -d memory_limit=3G vendor/bin/phpunit -c phpunit-w01d.xml --coverage-filter lib --coverage-clover /w/coverage/$T.clover.xml > ~/sig-lanes/l51-logs/w01d/pcov-$T.out 2>&1
rc=$?
echo "phpunit $T exit $rc"; grep -E "^(OK|Tests:|FAILURES|ERRORS)|risky|^[0-9]+\) " ~/sig-lanes/l51-logs/w01d/pcov-$T.out | head -30
