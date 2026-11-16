#!/bin/bash
# csdiff.sh <app>: phpcs errors on changed lib files that the HEAD version of the file does not have.
APP=$1; BASE=${BASE:-HEAD}
SP=/tmp/lanes/scratch; mkdir -p "$SP"
cd /tmp/lanes/ormig/$APP || exit 1
H=$(mktemp -d "$SP/head-$APP-XXXX")
FILES=$(git diff --name-only $BASE -- lib | grep '\.php$')
for f in $FILES; do mkdir -p "$H/$(dirname "$f")"; git show "$BASE:$f" > "$H/$f" 2>/dev/null || true; done
./vendor/bin/phpcs --standard=phpcs.xml --report=json $FILES 2>/dev/null > "$SP/cs-new-$APP.json"
(cd "$H" && /tmp/lanes/ormig/$APP/vendor/bin/phpcs --standard=/tmp/lanes/ormig/$APP/phpcs.xml --report=json $FILES 2>/dev/null) > "$SP/cs-old-$APP.json"
python3 /tmp/lanes/ormig/tools/csdiff.py "$SP" "$APP"
