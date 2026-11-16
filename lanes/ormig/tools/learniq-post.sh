#!/bin/bash
# learniq loads test helpers by require_once, not autoload
B=tests/bootstrap.php
grep -q 'Support/LegacyObjectArgs.php' $B || sed -i "s#^require_once __DIR__ . '/Support/GoldenFile.php';#&\nrequire_once __DIR__ . '/Support/ReleaseObjectSignatures.php';\nrequire_once __DIR__ . '/Support/LegacyObjectArgs.php';#" $B
