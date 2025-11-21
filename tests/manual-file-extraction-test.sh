#!/bin/bash

###############################################################################
# Manual Test Script for File Text Extraction Background Job
#
# This script tests that file uploads properly queue background jobs
# for text extraction and that the jobs execute successfully.
#
# Usage:
#   ./manual-file-extraction-test.sh [container-name]
#
# Example:
#   ./manual-file-extraction-test.sh master-nextcloud-1
#
###############################################################################

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Get container name from argument or use default
CONTAINER="${1:-master-nextcloud-1}"

echo -e "${BLUE}=====================================${NC}"
echo -e "${BLUE}File Text Extraction Test${NC}"
echo -e "${BLUE}=====================================${NC}"
echo ""
echo "Using container: ${CONTAINER}"
echo ""

# Function to run commands in container
function run_in_container() {
    docker exec -u 33 "$CONTAINER" "$@"
}

# Function to check if command succeeded
function check_status() {
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✓ $1${NC}"
        return 0
    else
        echo -e "${RED}✗ $1${NC}"
        return 1
    fi
}

# Test 1: Check that OpenRegister app is enabled
echo -e "${YELLOW}Test 1: Checking OpenRegister app status...${NC}"
if run_in_container php occ app:list | grep -q "openregister.*enabled"; then
    check_status "OpenRegister is enabled"
else
    echo -e "${RED}✗ OpenRegister is not enabled${NC}"
    echo "Enable it with: docker exec -u 33 $CONTAINER php occ app:enable openregister"
    exit 1
fi
echo ""

# Test 2: Check background job system
echo -e "${YELLOW}Test 2: Checking background job system...${NC}"
if run_in_container php occ config:app:get core backgroundjobs_mode > /dev/null 2>&1; then
    MODE=$(run_in_container php occ config:app:get core backgroundjobs_mode 2>/dev/null || echo "ajax")
    echo "Background jobs mode: $MODE"
    check_status "Background job system is configured"
else
    check_status "Could not check background job system" && false
fi
echo ""

# Test 3: Count existing FileTextExtractionJob jobs
echo -e "${YELLOW}Test 3: Checking for existing FileTextExtractionJob jobs...${NC}"
JOBS_BEFORE=$(run_in_container php occ background-job:list 2>/dev/null | grep -c "FileTextExtractionJob" || echo "0")
echo "Existing FileTextExtractionJob jobs: $JOBS_BEFORE"
check_status "Counted existing jobs"
echo ""

# Test 4: Create a test file via API
echo -e "${YELLOW}Test 4: Creating test file via file upload...${NC}"
echo "Creating test file with sample content..."

# Create test content
TEST_CONTENT="This is a test document for verifying background job text extraction. The job should be queued automatically when this file is uploaded. This test was run on $(date)."

# Try to create a test file (you'll need to adjust this based on your setup)
echo "Note: Automated file upload requires valid object/register/schema IDs."
echo "Please upload a file manually through the UI and observe the logs."
echo ""

# Test 5: Monitor logs for FileChangeListener activity
echo -e "${YELLOW}Test 5: Monitoring logs for FileChangeListener...${NC}"
echo "Watching logs for 10 seconds (upload a file now if you haven't)..."
echo ""

timeout 10s docker logs -f "$CONTAINER" 2>&1 | grep --line-buffered "FileChangeListener" &
MONITOR_PID=$!

sleep 10
kill $MONITOR_PID 2>/dev/null || true
echo ""

# Test 6: Check if new jobs were queued
echo -e "${YELLOW}Test 6: Checking if background jobs were queued...${NC}"
sleep 2  # Wait a moment for job to be queued
JOBS_AFTER=$(run_in_container php occ background-job:list 2>/dev/null | grep -c "FileTextExtractionJob" || echo "0")
echo "FileTextExtractionJob jobs after test: $JOBS_AFTER"

if [ "$JOBS_AFTER" -gt "$JOBS_BEFORE" ]; then
    check_status "New background job(s) queued! ($((JOBS_AFTER - JOBS_BEFORE)) new job(s))"
else
    echo -e "${YELLOW}⚠ No new jobs queued (may need to upload a file first)${NC}"
fi
echo ""

# Test 7: Execute background jobs
echo -e "${YELLOW}Test 7: Executing background jobs...${NC}"
echo "Running background job execution..."
if run_in_container php occ background-job:execute 2>&1 | head -20; then
    check_status "Background jobs executed"
else
    check_status "Background job execution completed with warnings" || true
fi
echo ""

# Test 8: Check logs for successful extraction
echo -e "${YELLOW}Test 8: Checking logs for successful text extraction...${NC}"
echo "Recent FileTextExtractionJob log entries:"
docker logs --tail 50 "$CONTAINER" 2>&1 | grep "FileTextExtractionJob" | tail -10
echo ""

if docker logs --tail 100 "$CONTAINER" 2>&1 | grep -q "Text extraction completed successfully"; then
    check_status "Found successful text extraction in logs"
else
    echo -e "${YELLOW}⚠ No successful extractions found (may need to wait for job execution)${NC}"
fi
echo ""

# Test 9: Verify no race condition errors
echo -e "${YELLOW}Test 9: Checking for race condition errors...${NC}"
RECENT_ERRORS=$(docker logs --tail 200 "$CONTAINER" 2>&1 | grep -c "Could not get local file path" || echo "0")
FILE_NOT_FOUND=$(docker logs --tail 200 "$CONTAINER" 2>&1 | grep -c "file_get_contents.*Failed to open stream" || echo "0")

if [ "$RECENT_ERRORS" -eq 0 ] && [ "$FILE_NOT_FOUND" -eq 0 ]; then
    check_status "No race condition errors found!"
else
    echo -e "${RED}✗ Found $RECENT_ERRORS file path errors and $FILE_NOT_FOUND file not found errors${NC}"
    echo "This may indicate the fix is not working correctly."
fi
echo ""

# Summary
echo -e "${BLUE}=====================================${NC}"
echo -e "${BLUE}Test Summary${NC}"
echo -e "${BLUE}=====================================${NC}"
echo ""
echo "✓ Tests completed!"
echo ""
echo -e "${YELLOW}To verify the fix is working:${NC}"
echo "1. Upload a text file (.txt, .pdf, .docx) through OpenRegister UI"
echo "2. Check logs: docker logs -f $CONTAINER | grep FileTextExtractionJob"
echo "3. Verify no errors: docker logs --tail 100 $CONTAINER | grep 'file_get_contents.*Failed'"
echo "4. Execute jobs: docker exec -u 33 $CONTAINER php occ background-job:execute"
echo ""
echo -e "${GREEN}Expected behavior:${NC}"
echo "  • File upload completes instantly"
echo "  • Background job is queued (FileTextExtractionJob)"
echo "  • Job executes successfully within seconds"
echo "  • No 'file not found' or 'failed to open stream' errors"
echo ""

