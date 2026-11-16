#!/bin/bash
# cscmp.sh <clone> <outprefix>: phpcs findings on modified lib files, development vs working tree (messages only, line numbers dropped)
c=$1; o=$2; cd "$c" || exit 2
F=$(git diff --name-only --diff-filter=M origin/development -- lib | grep 'php$')
for f in $F; do git show origin/development:$f | ./vendor/bin/phpcs -q --report=csv --stdin-path=$f - 2>/dev/null | tail -n +2 | sed "s#^[^,]*,#$f,#"; done | cut -d, -f1,4- | sort > $o-cs-before.txt
for f in $F; do ./vendor/bin/phpcs -q --report=csv $f 2>/dev/null | tail -n +2 | sed "s#^[^,]*,#$f,#"; done | cut -d, -f1,4- | sort > $o-cs-after.txt
echo "phpcs modified files before/after: $(wc -l < $o-cs-before.txt) $(wc -l < $o-cs-after.txt)"
N=$(git diff --name-only --diff-filter=A origin/development -- lib | grep 'php$'); [ -n "$N" ] && echo "new files: $(./vendor/bin/phpcs -q --report=csv $N | tail -n +2 | wc -l) findings"
diff $o-cs-before.txt $o-cs-after.txt > /dev/null && echo SAME-PHPCS || echo PHPCS-DIFFERS
