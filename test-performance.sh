#!/bin/bash

# Performance testing script for OpenRegister
# This script tests the problematic endpoints and shows detailed performance logs

echo "🚀 OpenRegister Performance Test - Authorization Bottleneck Investigation"
echo "========================================================================"

# Test endpoints that were reported as slow
endpoints=(
    "/index.php/apps/openregister/api/objects/voorzieningen/product?_limit=20&_page=1&_extend[]=%40self.schema"
    "/index.php/apps/openregister/api/objects/voorzieningen/organisatie?_limit=20&_page=1&_extend[]=%40self.schema"
    "/index.php/apps/opencatalogi/api/publications?_facetable=true&_search=test"
)

# Helper function to run a test and parse results
run_test() {
    local endpoint="$1"
    local test_name="$2"
    local extra_params="$3"
    
    echo "⏱️  Testing: $test_name"
    
    # Clear logs before test
    docker exec -u 33 master-nextcloud-1 bash -c "truncate -s 0 /var/www/html/data/nextcloud.log" 2>/dev/null || true
    
    # Make request and time it
    start_time=$(date +%s.%N)
    
    result=$(docker exec -u 33 master-nextcloud-1 bash -c "
        curl -u 'admin:admin' -H 'OCS-APIREQUEST: true' $extra_params \
             'http://localhost$endpoint' \
             -s -o /tmp/response.json -w '%{http_code}|%{time_total}|%{size_download}'
    ")
    
    end_time=$(date +%s.%N)
    total_time=$(echo "$end_time - $start_time" | bc)
    
    IFS='|' read -r http_code curl_time size_download <<< "$result"
    
    echo "   📊 HTTP Status: $http_code"
    echo "   ⚡ Total Time: ${total_time}s"
    echo "   🌐 Curl Time: ${curl_time}s" 
    echo "   📦 Response Size: ${size_download} bytes"
    
    # Extract performance metrics from logs
    echo "   🔍 Performance Breakdown:"
    docker logs master-nextcloud-1 2>&1 | tail -n 100 | grep -E "(RBAC FILTERING COMPLETED|ORG FILTERING COMPLETED|MAPPER COMPLETE)" | tail -n 3 | while read line; do
        if [[ $line =~ rbacTime.*([0-9]+(\.[0-9]+)?)ms ]]; then
            echo "      🔒 RBAC Time: ${BASH_REMATCH[1]}ms"
        elif [[ $line =~ orgTime.*([0-9]+(\.[0-9]+)?)ms ]]; then
            echo "      🏢 Org Filter Time: ${BASH_REMATCH[1]}ms"  
        elif [[ $line =~ totalMapperTime.*([0-9]+(\.[0-9]+)?)ms ]]; then
            echo "      🎯 Total Mapper Time: ${BASH_REMATCH[1]}ms"
        elif [[ $line =~ dbExecutionTime.*([0-9]+(\.[0-9]+)?)ms ]]; then
            echo "      💾 DB Execution Time: ${BASH_REMATCH[1]}ms"
        fi
    done
    
    # Return performance for comparison
    echo "$total_time"
}

echo "📊 Testing ${#endpoints[@]} endpoints with normal and bypassed authorization..."
echo ""

for i in "${!endpoints[@]}"; do
    endpoint="${endpoints[$i]}"
    echo "🔍 Test $((i+1))/${#endpoints[@]}: $(basename "$endpoint")"
    
    # Test with normal authorization
    normal_time=$(run_test "$endpoint" "Normal Authorization" "")
    
    echo ""
    
    # Test with bypassed authorization
    bypass_time=$(run_test "$endpoint&_bypass_auth=true" "Bypassed Authorization (⚠️  Testing Only)" "-H 'X-Bypass-Auth: true'")
    
    # Calculate performance difference
    if command -v bc >/dev/null 2>&1; then
        improvement=$(echo "scale=2; $normal_time / $bypass_time" | bc)
        echo ""
        echo "   📈 Performance Improvement: ${improvement}x faster without authorization"
        
        if (( $(echo "$improvement > 10" | bc -l) )); then
            echo "   🚨 MAJOR BOTTLENECK: Authorization layer is causing 10x+ slowdown!"
        elif (( $(echo "$improvement > 3" | bc -l) )); then
            echo "   ⚠️  SIGNIFICANT: Authorization layer is causing significant slowdown"
        else
            echo "   ✅ Authorization impact is minimal"
        fi
    fi
    
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
done

echo "🎉 Performance test completed!"
echo ""
echo "💡 Analysis Guide:"
echo "   🔥 If bypass shows >10x improvement: Authorization is the main bottleneck"
echo "   🔍 If RBAC time is >5000ms: RBAC logic needs optimization"
echo "   🏢 If Org filter time is >1000ms: Multi-tenancy logic needs optimization"  
echo "   💾 If DB execution time is high: Database/indexes need optimization"
echo "   🎯 If Mapper time >> DB time: PHP processing overhead is significant"
echo ""
echo "🛠️  Next steps if authorization is the bottleneck:"
echo "   1. Optimize RBAC queries and reduce database calls"
echo "   2. Add caching for authorization decisions"
echo "   3. Consider simplified authorization for specific endpoints"
echo "   4. Implement authorization result caching"
