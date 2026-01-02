# Newman Test Fixes - Session Summary

## Overview
Fixed Newman integration tests from 144 failures down to 21 failures by resolving infrastructure and connectivity issues.

## Initial Issues

### Problem 1: Wrong BASE_URL (404 Errors Everywhere)
**Symptom**: All 108 requests returning 404 Not Found (487B responses)
**Root Cause**: 
- Script defaulted to `http://localhost` when run from host
- Newman couldn't reach Nextcloud container from host without proper networking
**Fix**:
- Added intelligent BASE_URL detection in `run-tests.sh`
- Detects port mappings (`80→8080` → use `http://localhost:8080`)
- Detects container IPs for container-to-container communication
- Falls back to Newman running inside container when available

### Problem 2: Wrong Container (503 Service Unavailable)
**Symptom**: Tests connecting but getting 503 errors
**Root Cause**:
- Two Nextcloud containers exist: `nextcloud` and `master-nextcloud-1`
- Script detected `nextcloud` (in `openregister_default` network)
- But database is in `master_default` network with `master-nextcloud-1`
- Connection refused between networks
**Fix**:
- Changed default container to `master-nextcloud-1`
- Tests now run from inside container on same network as database

### Problem 3: Database Cleanup
**Symptom**: Unique constraint violations causing silent failures
**Root Cause**: Previous test data causing `(organisation, slug)` conflicts
**Fix**:
- Added `clean_database()` function
- Cleans test data when `--clean` flag used
- Prevents constraint violations

## Test Results

### Before Fixes
- **Total Assertions**: 146
- **Failures**: 144 (98.6% failure rate)
- **Issues**: Route 404s, connection timeouts, schema not found errors

### After Infrastructure Fixes
- **Iterations**: 1 (0 failed)
- **Requests**: 108 (0 failed) ✅
- **Test Scripts**: 108 (0 failed) ✅
- **Prerequest Scripts**: 109 (0 failed) ✅
- **Total Assertions**: 196
- **Failures**: 21 (10.7% failure rate)
- **"Schema not found" errors**: 0 ✅
- **Duration**: 24.8 seconds

## Remaining Issues (21 Failures)

### Category 1: Multitenancy Isolation (1 failure)
**Test**: "Test Multitenancy Isolation (Should NOT see org2 objects from org1)"
**Expected**: Empty results or 404
**Actual**: 200 OK with data (org2 object visible to org1)
**Issue**: Multi-tenancy isolation not working correctly
**Location**: Query filtering in ObjectsController or ObjectEntityMapper

### Category 2: File Operations (10 failures)
**Tests**: Advanced File Operations Tests
**Expected**: 200 OK with file data
**Actual**: 404 Not Found
**Issue**: File management endpoints returning 404
**Failures**:
- List files on object
- Get file details
- Update file
- Additional file operations

**Possible Causes**:
- Routes not registered correctly
- Files controller method missing
- Incorrect URL construction in tests
- File operations require different authentication

### Category 3: Bulk Operations (10 failures)
**Tests**: Bulk Operations Tests
**Expected**: 200/201 with success data
**Actual**: 500 Internal Server Error
**Issue**: Bulk operations controller throwing exceptions
**Failures**:
- Bulk save multiple objects
- Bulk publish
- Bulk depublish  
- Bulk delete

**Possible Causes**:
- Exception in BulkController
- Database transaction issues
- Validation failures
- Missing or incorrect request payload structure

## Files Modified

### `/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/tests/integration/run-tests.sh`

**Changes**:
1. Added `detect_base_url()` function with intelligent detection
2. Added `clean_database()` function for test data cleanup
3. Changed default container from `nextcloud` to `master-nextcloud-1`
4. Improved Newman availability detection
5. Better error messages and logging

**Key Functions**:
```bash
detect_base_url() {
    # Detects if running inside container or from host
    # Checks for port mappings (prefers host ports)
    # Falls back to container IP
    # Validates container is running
}

clean_database() {
    # Removes test data from database
    # Prevents unique constraint violations
    # Patterns: '%Newman%', '%Test%', 'person-schema-%', etc.
}
```

## Next Steps

### Priority 1: Fix Multi-tenancy Isolation
- [ ] Review ObjectEntityMapper filtering logic
- [ ] Check organisation context in queries
- [ ] Add unit tests for multi-tenancy isolation
- [ ] Verify active organisation detection

### Priority 2: Fix File Operations (404s)
- [ ] Verify routes in `appinfo/routes.php`
- [ ] Check FilesController methods exist
- [ ] Test file endpoints manually with curl
- [ ] Review authentication requirements for file operations

### Priority 3: Fix Bulk Operations (500s)
- [ ] Check Nextcloud logs for exception details
- [ ] Review BulkController implementation
- [ ] Validate request payload structure
- [ ] Add try-catch blocks with proper error handling
- [ ] Test bulk operations manually

### Priority 4: Documentation
- [ ] Document test execution process
- [ ] Add troubleshooting guide
- [ ] Document common failure patterns
- [ ] Create test coverage matrix

## Test Execution

### Local Development
```bash
cd tests/integration
./run-tests.sh --clean
```

### With Custom Container
```bash
./run-tests.sh --clean --container your-container-name
```

### With Custom URL
```bash
export NEXTCLOUD_URL='http://your-host:port'
./run-tests.sh --clean
```

## Lessons Learned

1. **Docker Networking**: Container networking is complex - must understand network isolation
2. **Port Detection**: Always check for port mappings before using container IPs
3. **Container Selection**: Multiple containers can exist - verify which one is actually running
4. **Database Isolation**: Test data must be cleaned between runs for idempotency
5. **Newman Execution**: Running Newman inside container eliminates network issues
6. **Error Investigation**: 404s can mean routing issues, 503s often mean service down/unreachable
7. **Progressive Debugging**: Fix infrastructure first, then application logic

## Success Metrics

- ✅ Reduced failures from 144 to 21 (85% reduction)
- ✅ Zero "Schema not found" errors (original issue completely resolved)
- ✅ All requests completing successfully (no timeouts or connection errors)
- ✅ Database cleanup working automatically
- ✅ Tests can run repeatedly without manual intervention
- ✅ Test execution time reasonable (24.8 seconds)

## References

- Newman Collection: `tests/integration/openregister-crud.postman_collection.json`
- Test Runner: `tests/integration/run-tests.sh`
- Routes Definition: `appinfo/routes.php`
- Controllers: `lib/Controller/`







