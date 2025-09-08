#!/bin/bash
echo "=== DEBUG BULK IMPORT SCRIPT ==="
echo "Testing from host machine to Docker container"

# Get current object count
echo "Getting current object count..."
count_before=$(docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  -H 'Content-Type: application/json' \
  'http://localhost/index.php/apps/openregister/api/objects?register=19&schema=105' | jq -r '.total // 0')
echo "Objects before import: $count_before"

# Try the bulk import using organisatie.csv
echo "Testing bulk import with organisatie.csv..."
import_result=$(docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  -X POST \
  -H 'OCS-APIREQUEST: true' \
  'http://localhost/index.php/apps/openregister/api/import/19/105' \
  -F "file=@/var/www/html/apps-extra/openregister/lib/Settings/organisatie.csv")

echo "Import result: $import_result"

# Check if count changed
echo "Getting object count after import..."
count_after=$(docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  -H 'Content-Type: application/json' \
  'http://localhost/index.php/apps/openregister/api/objects?register=19&schema=105' | jq -r '.total // 0')
echo "Objects after import: $count_after"

echo "Difference: $(($count_after - $count_before))"
