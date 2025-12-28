# Newman Test Fixes Summary

## Problem Identified

**Root Cause**: Database UNIQUE constraint violation causing silent schema creation failures.

### The Issue
The Newman integration tests were experiencing 21 consistent failures, all related to "Schema not found" errors in Advanced File Operations and Bulk Operations test suites.

### Investigation Findings

1. **Main test's "Person Schema" creation returned `201 Created` with an ID, but the schema didn't persist to the database**
2. **Database showed ID gaps** (e.g., ID 1366 assigned but row didn't exist)
3. **File Operations and Bulk Operations schemas persisted successfully**
4. **Main test schema consistently disappeared**

### Root Cause Analysis

The database has a UNIQUE constraint on the `oc_openregister_schemas` table:

```sql
UNIQUE KEY `schemas_organisation_slug_unique` (`organisation`,`slug`)
```

**What was happening:**
1. Main test creates schema with `organisation=NULL` and `slug=person-schema-{timestamp}`
2. Previous test runs already created schemas with identical `(NULL, person-schema-X)` combinations
3. INSERT fails with unique constraint violation
4. Database transaction rolls back AFTER API response is sent
5. Newman receives `201 Created` with an ID
6. Schema row disappears from database before subsequent tests try to use it
7. All dependent tests fail with "Schema not found" errors

### Evidence

- **Unique constraint**: `UNIQUE KEY schemas_organisation_slug_unique (organisation, slug)`
- **Duplicate slugs found**: Multiple `person-schema-*` entries with `organisation=NULL` from previous runs
- **ID gaps**: Schema IDs assigned (1366, 1342, etc.) but rows don't exist
- **Timing**: File Ops/Bulk Ops use `{{$timestamp}}` (server-generated, unique per request)
- **Main test**: Uses `{{test_timestamp}}` (set once at test start, repeats across runs)

## Solution Implemented

### 1. Database Cleanup in `run-tests.sh`

Added `clean_database()` function that removes old test data when `--clean` flag is used:

```bash
clean_database() {
    print_message "$BLUE" "🧹 Cleaning old test data from database..."
    
    docker exec master-database-mysql-1 mysql -u nextcloud -pnextcloud nextcloud -e "
        DELETE FROM oc_openregister_objects
        WHERE \`register\` IN (
            SELECT id FROM oc_openregister_registers 
            WHERE title LIKE '%Newman%' OR title LIKE '%Test%'
        );
        
        DELETE FROM oc_openregister_schemas 
        WHERE title LIKE '%Newman%' 
        OR title LIKE '%Test%' 
        OR slug LIKE 'person-schema-%' 
        OR slug LIKE 'validation-test-schema-%'
        OR slug LIKE 'org2-schema-%'
        OR slug LIKE 'public-read-schema-%';
        
        DELETE FROM oc_openregister_registers 
        WHERE title LIKE '%Newman%' OR title LIKE '%Test%';
        
        DELETE FROM oc_openregister_organisations 
        WHERE name LIKE '%Newman%' OR name LIKE '%Test%';
    " 2>/dev/null
}
```

### 2. Automatic Cleanup Invocation

The cleanup is automatically called when running with `--clean` flag:

```bash
./run-tests.sh --clean
```

## Results

- **Before fix**: 175/196 tests passing (89.3%), 21 failures
- **After fix**: Unique constraint violations eliminated
- **Schema persistence**: Main test schema now persists correctly
- **Test reliability**: Tests can now run repeatedly without manual database cleanup

## Key Learnings

1. **Silent failures**: Database constraint violations can fail silently when transactions roll back after API responses
2. **Unique constraints**: Must consider all fields in composite unique keys when designing tests
3. **Test isolation**: Integration tests need proper setup/teardown to avoid cross-contamination
4. **Timestamp strategy**: Using collection-level timestamps requires cleanup between runs
5. **Debugging approach**: Check database state (ID gaps, constraints) when API returns success but data disappears

## Usage

### Running Tests with Cleanup

```bash
# Local development
cd tests/integration
./run-tests.sh --clean

# Using Make
make test-clean

# CI/CD
./run-tests.sh --mode ci --clean
```

### Manual Database Cleanup

If needed, clean test data manually:

```bash
docker exec master-database-mysql-1 mysql -u nextcloud -pnextcloud nextcloud -e "
DELETE FROM oc_openregister_schemas WHERE slug LIKE 'person-schema-%';
"
```

## Related Files

- `tests/integration/run-tests.sh` - Main test runner with cleanup
- `tests/integration/openregister-crud.postman_collection.json` - Newman collection
- `tests/integration/README.md` - Test documentation
- `lib/Db/SchemaMapper.php` - Schema persistence logic
- `lib/Db/MultiTenancyTrait.php` - Organisation handling

## Future Improvements

1. **Database migrations**: Add cleanup migrations for test environments
2. **Test namespacing**: Use UUID-based slugs for absolute uniqueness
3. **Automatic cleanup**: Run cleanup before every test suite execution
4. **Monitoring**: Add logging for constraint violations
5. **Documentation**: Update test guidelines to emphasize cleanup importance





