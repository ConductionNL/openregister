#!/bin/bash
echo "=== DEBUG BULK IMPORT SCRIPT ==="
echo "Testing from host machine to Docker container"

# Test SOLR connectivity and status
echo "=== SOLR CONNECTIVITY TESTS ==="
echo "Testing SOLR service status..."

# Check if SOLR container is running
solr_status=$(docker ps --filter "name=openregister-solr" --format "table {{.Names}}\t{{.Status}}" | grep -v NAMES)
if [ -n "$solr_status" ]; then
    echo "✓ SOLR container status: $solr_status"
else
    echo "✗ SOLR container not found or not running"
fi

# Test SOLR admin API from host
echo "Testing SOLR admin API from host..."
solr_ping_host=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:8983/solr/admin/ping")
echo "SOLR admin ping from host: HTTP $solr_ping_host"

# Test SOLR admin API from within nextcloud container
echo "Testing SOLR admin API from nextcloud container..."
solr_ping_internal=$(docker exec -u 33 master-nextcloud-1 curl -s -o /dev/null -w "%{http_code}" "http://solr:8983/solr/admin/ping" 2>/dev/null || echo "000")
echo "SOLR admin ping from nextcloud container: HTTP $solr_ping_internal"

# Check openregister core status
echo "Checking openregister core..."
core_status=$(curl -s "http://localhost:8983/solr/admin/cores?action=STATUS&core=openregister" | grep -o '"name":"openregister"' || echo "not found")
if [[ "$core_status" == *"openregister"* ]]; then
    echo "✓ OpenRegister core exists"
    # Get core stats
    core_docs=$(curl -s "http://localhost:8983/solr/openregister/select?q=*:*&rows=0" | grep -o '"numFound":[0-9]*' | cut -d':' -f2 || echo "0")
    echo "  └ Documents in SOLR: $core_docs"
else
    echo "✗ OpenRegister core not found"
fi
echo ""

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

echo ""
echo "=== FINAL SOLR STATUS ==="
# Final SOLR check
final_solr_docs=$(curl -s "http://localhost:8983/solr/openregister/select?q=*:*&rows=0" 2>/dev/null | grep -o '"numFound":[0-9]*' | cut -d':' -f2 || echo "0")
echo "Final documents in SOLR: $final_solr_docs"
echo "SOLR Admin UI available at: http://localhost:8983/solr/"
echo "OpenRegister core URL: http://localhost:8983/solr/#/openregister"

echo ""
echo "=== NEXT STEPS ==="
echo "1. Install Solarium PHP library: composer require solarium/solarium"
echo "2. Configure SOLR connection in your Nextcloud app"
echo "3. Implement SOLR indexing for OpenRegister objects"
echo "4. Set up automatic sync between database and SOLR index"
