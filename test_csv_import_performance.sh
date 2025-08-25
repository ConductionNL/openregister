#!/bin/bash

# OpenRegister CSV Import Performance Test Script
# This script imports all CSV files from lib/Settings and measures performance

CONTAINER_NAME="master-nextcloud-1"
REGISTER_ID="19"  # Voorzieningen register
BASE_URL="http://localhost/index.php/apps/openregister/api"

# CSV file to schema ID mapping (based on API response)
declare -A SCHEMA_MAPPING=(
    ["compliancy.csv"]="117"
    ["gebruik.csv"]="106"
    ["koppeling.csv"]="108"
    ["module.csv"]="116"
    ["moduleversie.csv"]="118"
    ["organisatie.csv"]="105"
    ["product.csv"]="101"
)

# Test configurations for performance optimization
declare -A CHUNK_SIZES=(
    ["default"]="5"
    ["small"]="2"
    ["medium"]="10"
    ["large"]="25"
)

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

log() {
    echo -e "${BLUE}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} $1"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

warn() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

# Function to import a single CSV file and measure performance
import_csv_file() {
    local csv_file="$1"
    local schema_id="$2"
    local chunk_size="$3"
    local test_name="$4"
    
    local file_path="/var/www/html/apps-extra/openregister/lib/Settings/$csv_file"
    local line_count=$(docker exec -u 33 "$CONTAINER_NAME" bash -c "wc -l < '$file_path'" 2>/dev/null || echo "0")
    
    log "Testing $test_name: $csv_file (${line_count} lines) with chunk size $chunk_size"
    
    # Start timing
    local start_time=$(date +%s.%3N)
    
    # Execute import in container
    local import_result=$(docker exec -u 33 "$CONTAINER_NAME" bash -c "
        curl -s -w 'HTTP_STATUS:%{http_code}|TIME_TOTAL:%{time_total}' \
             -u 'admin:admin' \
             -X POST \
             -F 'file=@$file_path' \
             -F 'type=csv' \
             -F 'schema=$schema_id' \
             -F 'chunkSize=$chunk_size' \
             -F 'validation=false' \
             -F 'events=false' \
             -F 'rbac=true' \
             -F 'multi=true' \
             -F 'publish=false' \
             '$BASE_URL/registers/$REGISTER_ID/import'
    " 2>/dev/null)
    
    local end_time=$(date +%s.%3N)
    local total_time=$(echo "$end_time - $start_time" | bc -l 2>/dev/null || echo "0")
    
    # Extract HTTP status and curl time
    local http_status=$(echo "$import_result" | grep -o 'HTTP_STATUS:[0-9]*' | cut -d: -f2)
    local curl_time=$(echo "$import_result" | grep -o 'TIME_TOTAL:[0-9.]*' | cut -d: -f2)
    local response_body=$(echo "$import_result" | sed 's/HTTP_STATUS:[0-9]*|TIME_TOTAL:[0-9.]*//')
    
    # Parse response to get statistics
    local created_count=$(echo "$response_body" | jq -r '.created // [] | length' 2>/dev/null || echo "0")
    local updated_count=$(echo "$response_body" | jq -r '.updated // [] | length' 2>/dev/null || echo "0")
    local errors_count=$(echo "$response_body" | jq -r '.errors // [] | length' 2>/dev/null || echo "0")
    local found_count=$(echo "$response_body" | jq -r '.found // 0' 2>/dev/null || echo "0")
    
    # Calculate performance metrics
    local records_per_second=$(echo "scale=2; $found_count / $total_time" | bc -l 2>/dev/null || echo "0")
    local minutes=$(echo "scale=2; $total_time / 60" | bc -l 2>/dev/null || echo "0")
    
    # Determine result status
    local status_icon=""
    local status_color=""
    if [ "$http_status" = "200" ]; then
        if (( $(echo "$minutes < 2.0" | bc -l) )); then
            status_icon="✅"
            status_color="$GREEN"
        else
            status_icon="⚠️"
            status_color="$YELLOW"
        fi
    else
        status_icon="❌"
        status_color="$RED"
    fi
    
    echo -e "${status_color}${status_icon} $test_name Results:${NC}"
    echo "   File: $csv_file"
    echo "   Lines: $line_count"
    echo "   Chunk Size: $chunk_size"
    echo "   Total Time: ${total_time}s (${minutes} min)"
    echo "   HTTP Status: $http_status"
    echo "   Found: $found_count"
    echo "   Created: $created_count"
    echo "   Updated: $updated_count"
    echo "   Errors: $errors_count"
    echo "   Performance: ${records_per_second} records/sec"
    echo "   Curl Time: ${curl_time}s"
    
    # Log detailed results to CSV for analysis
    echo "$test_name,$csv_file,$line_count,$chunk_size,$total_time,$minutes,$http_status,$found_count,$created_count,$updated_count,$errors_count,$records_per_second,$curl_time" >> performance_results.csv
    
    echo ""
    
    # If there were errors, show first few
    if [ "$errors_count" != "0" ] && [ "$errors_count" != "null" ]; then
        warn "First 3 errors:"
        echo "$response_body" | jq -r '.errors[:3][] | "  - " + (.error // "Unknown error")' 2>/dev/null || echo "  - Could not parse errors"
        echo ""
    fi
    
    return $([ "$http_status" = "200" ] && echo 0 || echo 1)
}

# Function to test all files with different chunk sizes
test_import_performance() {
    log "Starting CSV Import Performance Test"
    echo "Test Name,CSV File,Lines,Chunk Size,Total Time (s),Time (min),HTTP Status,Found,Created,Updated,Errors,Records/sec,Curl Time (s)" > performance_results.csv
    
    local total_tests=0
    local passed_tests=0
    local under_2min_tests=0
    
    # Test each CSV file with different chunk sizes
    for csv_file in "${!SCHEMA_MAPPING[@]}"; do
        local schema_id="${SCHEMA_MAPPING[$csv_file]}"
        
        # Test with different chunk sizes
        for chunk_name in "${!CHUNK_SIZES[@]}"; do
            local chunk_size="${CHUNK_SIZES[$chunk_name]}"
            local test_name="${csv_file%.csv}_${chunk_name}"
            
            if import_csv_file "$csv_file" "$schema_id" "$chunk_size" "$test_name"; then
                ((passed_tests++))
                # Check if under 2 minutes (120 seconds)
                local last_time=$(tail -n 1 performance_results.csv | cut -d, -f5)
                if (( $(echo "$last_time < 120.0" | bc -l 2>/dev/null || echo "0") )); then
                    ((under_2min_tests++))
                fi
            fi
            ((total_tests++))
            
            # Small delay between tests
            sleep 2
        done
        
        echo "----------------------------------------"
    done
    
    # Summary
    echo ""
    log "Test Summary:"
    echo "   Total Tests: $total_tests"
    echo "   Passed Tests: $passed_tests"
    echo "   Tests Under 2min: $under_2min_tests"
    echo "   Success Rate: $(echo "scale=1; $passed_tests * 100 / $total_tests" | bc -l)%"
    echo "   Under 2min Rate: $(echo "scale=1; $under_2min_tests * 100 / $total_tests" | bc -l)%"
    echo ""
    echo "Results saved to: performance_results.csv"
    
    # Show top performers
    echo ""
    log "Top 5 Fastest Imports (by records/second):"
    sort -t, -k12 -nr performance_results.csv | head -6 | tail -5 | while IFS=, read -r test_name csv_file lines chunk_size total_time time_min http_status found created updated errors records_per_sec curl_time; do
        echo "   $test_name: $records_per_sec records/sec (${time_min} min)"
    done
    
    # Show problematic files
    echo ""
    warn "Files Taking Over 2 Minutes:"
    awk -F, 'NR>1 && $6 > 2.0 {print "   " $1 ": " $6 " min (" $3 " lines)"}' performance_results.csv | sort -t: -k2 -nr
}

# Function to optimize specific file (moduleversie.csv)
optimize_moduleversie() {
    log "Optimizing moduleversie.csv import..."
    
    local csv_file="moduleversie.csv"
    local schema_id="118"
    
    echo "Optimization Test,CSV File,Lines,Chunk Size,Total Time (s),Time (min),HTTP Status,Found,Created,Updated,Errors,Records/sec,Curl Time (s)" > moduleversie_optimization.csv
    
    # Test different optimization settings for moduleversie.csv
    local optimizations=(
        "chunk_1:1:true:false:false:false"         # Very small chunks, no validation/events/publish
        "chunk_3:3:true:false:false:false"         # Small chunks
        "chunk_5:5:true:false:false:false"         # Default small chunks  
        "chunk_10:10:true:false:false:false"       # Medium chunks
        "chunk_20:20:true:false:false:false"       # Larger chunks
        "chunk_50:50:true:false:false:false"       # Very large chunks
        "no_rbac:5:false:false:false:false"        # Disable RBAC
        "no_multi:5:true:true:false:false"         # Disable multi-threading
        "validation:5:true:false:true:false"       # Enable validation
        "events:5:true:false:false:true"           # Enable events
        "all_off:10:false:true:false:false"        # All optimizations off
    )
    
    for optimization in "${optimizations[@]}"; do
        IFS=':' read -r test_name chunk_size rbac multi validation events <<< "$optimization"
        
        log "Testing $test_name optimization (chunk: $chunk_size, rbac: $rbac, multi: $multi, validation: $validation, events: $events)"
        
        local file_path="/var/www/html/apps-extra/openregister/lib/Settings/$csv_file"
        local line_count=$(docker exec -u 33 "$CONTAINER_NAME" bash -c "wc -l < '$file_path'" 2>/dev/null || echo "0")
        
        local start_time=$(date +%s.%3N)
        
        local import_result=$(docker exec -u 33 "$CONTAINER_NAME" bash -c "
            curl -s -w 'HTTP_STATUS:%{http_code}|TIME_TOTAL:%{time_total}' \
                 -u 'admin:admin' \
                 -X POST \
                 -F 'file=@$file_path' \
                 -F 'type=csv' \
                 -F 'schema=$schema_id' \
                 -F 'chunkSize=$chunk_size' \
                 -F 'validation=$validation' \
                 -F 'events=$events' \
                 -F 'rbac=$rbac' \
                 -F 'multi=$multi' \
                 -F 'publish=false' \
                 '$BASE_URL/registers/$REGISTER_ID/import'
        " 2>/dev/null)
        
        local end_time=$(date +%s.%3N)
        local total_time=$(echo "$end_time - $start_time" | bc -l 2>/dev/null || echo "0")
        
        # Parse results
        local http_status=$(echo "$import_result" | grep -o 'HTTP_STATUS:[0-9]*' | cut -d: -f2)
        local curl_time=$(echo "$import_result" | grep -o 'TIME_TOTAL:[0-9.]*' | cut -d: -f2)
        local response_body=$(echo "$import_result" | sed 's/HTTP_STATUS:[0-9]*|TIME_TOTAL:[0-9.]*//')
        
        local created_count=$(echo "$response_body" | jq -r '.created // [] | length' 2>/dev/null || echo "0")
        local updated_count=$(echo "$response_body" | jq -r '.updated // [] | length' 2>/dev/null || echo "0")
        local errors_count=$(echo "$response_body" | jq -r '.errors // [] | length' 2>/dev/null || echo "0")
        local found_count=$(echo "$response_body" | jq -r '.found // 0' 2>/dev/null || echo "0")
        
        local records_per_second=$(echo "scale=2; $found_count / $total_time" | bc -l 2>/dev/null || echo "0")
        local minutes=$(echo "scale=2; $total_time / 60" | bc -l 2>/dev/null || echo "0")
        
        # Log results
        echo "$test_name,$csv_file,$line_count,$chunk_size,$total_time,$minutes,$http_status,$found_count,$created_count,$updated_count,$errors_count,$records_per_second,$curl_time" >> moduleversie_optimization.csv
        
        local status_icon="✅"
        if [ "$http_status" != "200" ]; then
            status_icon="❌"
        elif (( $(echo "$minutes > 2.0" | bc -l 2>/dev/null || echo "0") )); then
            status_icon="⚠️"
        fi
        
        echo "   $status_icon Result: ${minutes} min ($records_per_second rec/sec) - Status: $http_status"
        
        sleep 2
    done
    
    echo ""
    log "Moduleversie optimization results saved to: moduleversie_optimization.csv"
    
    # Show best result
    echo ""
    log "Best moduleversie.csv Performance:"
    sort -t, -k6 -n moduleversie_optimization.csv | head -2 | tail -1 | while IFS=, read -r test_name csv_file lines chunk_size total_time time_min http_status found created updated errors records_per_sec curl_time; do
        echo "   Best: $test_name - ${time_min} min (${records_per_sec} records/sec)"
    done
}

# Main execution
main() {
    log "OpenRegister CSV Import Performance Test"
    echo "Container: $CONTAINER_NAME"
    echo "Register ID: $REGISTER_ID"
    echo "Files to test: ${#SCHEMA_MAPPING[@]}"
    echo ""
    
    # Check if container is running
    if ! docker ps --format '{{.Names}}' | grep -q "^$CONTAINER_NAME$"; then
        error "Container $CONTAINER_NAME is not running!"
        exit 1
    fi
    
    # Check if app is enabled
    local app_status=$(docker exec -u 33 "$CONTAINER_NAME" php /var/www/html/occ app:list | grep openregister || echo "not found")
    if [[ "$app_status" == *"not found"* ]]; then
        error "OpenRegister app is not enabled!"
        exit 1
    fi
    
    success "Container and app checks passed"
    echo ""
    
    # Run performance tests
    test_import_performance
    
    # Run specific optimization for moduleversie.csv
    echo ""
    optimize_moduleversie
    
    log "All tests completed!"
}

# Run main function
main "$@"
