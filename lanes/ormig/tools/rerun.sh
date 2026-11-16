#!/bin/bash
# rerun.sh <app> <prevlog> <outlog> : rerun only the classes that failed in prevlog
cd /tmp/lanes/ormig/$1
CL=$(grep -E '^[0-9]+\) ' ../logs/$2 | awk '{print $2}' | sed 's/::.*//;s/.*\\//' | sort -u | paste -sd'|')
[ -z "$CL" ] && { echo none; exit 0; }
timeout 1200 env $ENVX php -d memory_limit=4G vendor/bin/phpunit --no-coverage --colors=never --filter "/($CL)::/" > ../logs/$3 2>&1
grep '^Tests:\|^OK' ../logs/$3
grep -E '^[0-9]+\) ' ../logs/$3 | sed 's/ with data set.*//' | awk '{print $2}' | sed 's/::.*//;s/.*\\//' | sort | uniq -c | sort -rn | awk '{printf "%s:%s  ", $2, $1} END {print ""}'
