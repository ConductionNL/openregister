#!/bin/bash
# pmdcmp.sh <clone> <outprefix>: phpmd rule counts per modified/new lib file, development vs working tree, private HOME (no shared pdepend cache)
c=$1; o=$2; cd "$c" || exit 2
export HOME=$(mktemp -d /tmp/pmdhome.XXXX)
F=$(git diff --name-only --diff-filter=M origin/development -- lib | grep 'php$')
N=$(git diff --name-only --diff-filter=A origin/development -- lib | grep 'php$')
cfg=phpmd.xml; [ -f $cfg ] || cfg=.phpmd.xml
tmp=$(mktemp -d)
for f in $F; do mkdir -p $tmp/$(dirname $f); git show origin/development:$f > $tmp/$f; ./vendor/bin/phpmd $tmp/$f text $c/$cfg 2>/dev/null | sed -E 's#^[^:]*:[0-9]+[[:space:]]+##' | awk '{print $1}' | sort | uniq -c | sed "s#^#$f #"; done | sort > $o-pmd-before.txt
for f in $F $N; do ./vendor/bin/phpmd $f text $cfg 2>/dev/null | sed -E 's#^[^:]*:[0-9]+[[:space:]]+##' | awk '{print $1}' | sort | uniq -c | sed "s#^#$f #"; done | sort > $o-pmd-after.txt
rm -rf $tmp "$HOME"
diff $o-pmd-before.txt $o-pmd-after.txt && echo SAME-PHPMD || echo PHPMD-DIFFERS
