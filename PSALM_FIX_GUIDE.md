# Psalm Fix Guide - Practical Examples

This guide provides specific examples of how to fix the most common Psalm errors in OpenRegister.

## Quick Start: Auto-Fix What You Can

```bash
cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister

# 1. Run auto-fix for docblock issues (16 fixes)
./vendor/bin/psalm --alter --issues=InvalidReturnType,MismatchingDocblockReturnType,InvalidNullableReturnType,LessSpecificReturnType

# 2. Regenerate baseline to remove old entries
./vendor/bin/psalm --set-baseline=psalm-baseline.xml

# 3. Check results
composer psalm
```

## Fix Category 1: UndefinedMethod (HIGH PRIORITY)

### Problem: Method doesn't exist

**Error:**
```
ERROR: UndefinedMethod - lib/Service/Object/QueryHandler.php:189:51
Method OCA\OpenRegister\Db\ObjectEntityMapper::searchObjects does not exist
```

**Bad Code:**
```php
$result = $this->objectEntityMapper->searchObjects(
    query: $query,
    limit: $limit,
    offset: $offset
);
```

**Fix Options:**

#### Option A: Method exists but has different name
```php
// If the actual method is named 'findAll' or 'search':
$result = $this->objectEntityMapper->findAll(
    query: $query,
    limit: $limit,
    offset: $offset
);
```

#### Option B: Implement the missing method
```php
// In ObjectEntityMapper.php, add:

/**
 * Search for objects based on query criteria.
 *
 * @param array<string, mixed> $query Query parameters
 * @param int $limit Maximum number of results
 * @param int $offset Offset for pagination
 *
 * @return array<int, ObjectEntity> Found objects
 */
public function searchObjects(array $query, int $limit = 100, int $offset = 0): array
{
    // Implementation here.
    return $this->findAll();
}
```

#### Option C: Use existing method from parent class
```php
// If method exists in parent QBMapper:
$result = $this->objectEntityMapper->findEntities(
    $this->buildQueryFromArray($query)
);
```

### Common Missing Methods to Fix

1. **ObjectEntityMapper::find()**
   - Check if it should be `findById()` or `findByUuid()`
   - Or implement: `public function find(string $uuid): ObjectEntity`

2. **ObjectEntityMapper::findAll()**
   - Check if it should be `findEntities()` or `findMultiple()`
   - Or implement: `public function findAll(array $ids, bool $includeDeleted = false): array`

3. **ObjectEntityMapper::findBySchema()**
   - Implement: `public function findBySchema(int $schemaId): array`

4. **ObjectEntityMapper::countAll()**
   - Implement: `public function countAll(array $filters = [], ?Schema $schema = null): int`

5. **RenderObject::renderEntities()**
   - Check if it should be `render()` or `renderMultiple()`

## Fix Category 2: InvalidNamedArgument (HIGH PRIORITY)

### Problem: Named parameter doesn't exist

**Error:**
```
ERROR: InvalidNamedArgument - lib/Service/ObjectService.php:363:83
Parameter $rbac does not exist on function OCA\OpenRegister\Db\RegisterMapper::find
```

**Bad Code:**
```php
$register = $this->registerMapper->find(
    id: $ids[0],
    published: null,
    rbac: false,        // ← This parameter doesn't exist!
    multi: false        // ← This parameter doesn't exist!
);
```

**Fix: Check the actual method signature**

```php
// Look at RegisterMapper::find() signature:
public function find(int|string $id, ?bool $published = null): Register
{
    // ...
}

// Remove invalid parameters:
$register = $this->registerMapper->find(
    id: $ids[0],
    published: null
);
```

### Common Invalid Parameters to Remove

1. **rbac: false** - This parameter was likely removed during refactoring
   - Remove it from all method calls
   - Check if RBAC logic moved elsewhere

2. **multi: false** - This parameter was removed
   - Remove it from all method calls
   - Check if multitenancy logic moved elsewhere

3. **extend: ...** - Parameter name might be wrong
   - Check if it should be `extends` or `parent`

4. **register: ...** - In validation contexts
   - Check if it should be `schema` instead

## Fix Category 3: UndefinedVariable (HIGH PRIORITY)

### Problem: Variable used before definition

**Error:**
```
ERROR: UndefinedVariable - lib/Service/ObjectService.php:482:20
Cannot find referenced variable $multi
```

**Bad Code:**
```php
public function find(string $id)
{
    return $this->getObject->find(
        id: $id,
        multi: $multi  // ← $multi is never defined!
    );
}
```

**Fix Option A: Remove the parameter**
```php
public function find(string $id)
{
    return $this->getObject->find(
        id: $id
    );
}
```

**Fix Option B: Add the parameter to method signature**
```php
public function find(string $id, bool $multi = false)
{
    return $this->getObject->find(
        id: $id,
        multi: $multi
    );
}
```

**Fix Option C: Get the value from elsewhere**
```php
public function find(string $id)
{
    $multi = $this->isMultitenancyEnabled();
    return $this->getObject->find(
        id: $id,
        multi: $multi
    );
}
```

## Fix Category 4: TypeDoesNotContainNull (MEDIUM PRIORITY)

### Problem: Unnecessary null coalescing

**Error:**
```
ERROR: TypeDoesNotContainNull - lib/Service/Object/SaveObjects.php:1404:41
Cannot resolve types for $createdObjects - list<mixed> does not contain null
```

**Bad Code:**
```php
/**
 * @param array<int, ObjectEntity> $createdObjects Created objects
 */
public function process(array $createdObjects): void
{
    // $createdObjects is already typed as array, never null!
    foreach ($createdObjects ?? [] as $createdObj) {
        // Process...
    }
}
```

**Fix: Remove unnecessary null coalescing**
```php
/**
 * @param array<int, ObjectEntity> $createdObjects Created objects
 */
public function process(array $createdObjects): void
{
    foreach ($createdObjects as $createdObj) {
        // Process...
    }
}
```

**Bulk Fix Strategy:**
```bash
# Find all occurrences in a file:
grep -n "?? \[\]" lib/Service/Object/SaveObjects.php

# Review each one and if the variable is typed as non-nullable array, remove ` ?? []`
```

## Fix Category 5: UndefinedThisPropertyFetch (HIGH PRIORITY)

### Problem: Property not declared in class

**Error:**
```
ERROR: UndefinedThisPropertyFetch - lib/Service/ObjectService.php:4013:17
Instance property OCA\OpenRegister\Service\ObjectService::$objectCacheService is not defined
```

**Bad Code:**
```php
class ObjectService
{
    // No property declaration!

    public function __construct(
        private ObjectEntityMapper $objectEntityMapper
    ) {
    }

    public function clearCache(): void
    {
        $this->objectCacheService->clear();  // ← Property doesn't exist!
    }
}
```

**Fix: Add property and inject via constructor**
```php
class ObjectService
{
    private ObjectCacheService $objectCacheService;

    public function __construct(
        private ObjectEntityMapper $objectEntityMapper,
        ObjectCacheService $objectCacheService  // ← Add to constructor
    ) {
        $this->objectCacheService = $objectCacheService;  // ← Assign it.
    }

    public function clearCache(): void
    {
        $this->objectCacheService->clear();  // ← Now it works!
    }
}
```

**Alternative: Use property promotion (PHP 8.1+)**
```php
class ObjectService
{
    public function __construct(
        private ObjectEntityMapper $objectEntityMapper,
        private ObjectCacheService $objectCacheService  // ← Declares and assigns!
    ) {
    }

    public function clearCache(): void
    {
        $this->objectCacheService->clear();  // ← Works!
    }
}
```

## Fix Category 6: TypeDoesNotContainType (MEDIUM PRIORITY)

### Problem: Impossible type check

**Error:**
```
ERROR: TypeDoesNotContainType - lib/Service/Object/PermissionHandler.php:375:17
Type OCA\OpenRegister\Db\Organisation|null for $activeOrganisation is never array<array-key, mixed>
```

**Bad Code:**
```php
/**
 * @param Organisation|null $activeOrganisation Active organisation
 */
public function check(Organisation|null $activeOrganisation): string
{
    // $activeOrganisation is Organisation or null, NEVER an array!
    if (is_array($activeOrganisation) === true) {
        return $activeOrganisation['uuid'];  // This code never runs!
    }
}
```

**Fix: Remove impossible checks**
```php
/**
 * @param Organisation|null $activeOrganisation Active organisation
 */
public function check(Organisation|null $activeOrganisation): ?string
{
    if ($activeOrganisation === null) {
        return null;
    }
    
    return $activeOrganisation->getUuid();  // Access as object, not array.
}
```

**If you need array access, fix the type:**
```php
/**
 * @param Organisation|array<string, mixed>|null $activeOrganisation Active organisation
 */
public function check(Organisation|array|null $activeOrganisation): ?string
{
    if (is_array($activeOrganisation) === true) {
        return $activeOrganisation['uuid'] ?? null;
    }
    
    if ($activeOrganisation instanceof Organisation) {
        return $activeOrganisation->getUuid();
    }
    
    return null;
}
```

## Fix Category 7: NoValue (Dead Code)

### Problem: All code paths invalidated

**Error:**
```
ERROR: NoValue - lib/Service/Object/PermissionHandler.php:380:17
All possible types for this return were invalidated - This may be dead code
```

**Bad Code:**
```php
public function getActiveOrg(Organisation|null $activeOrganisation): string
{
    if (is_array($activeOrganisation) === true && isset($activeOrganisation['uuid']) === true) {
        return $activeOrganisation['uuid'];
    }
    
    if (is_string($activeOrganisation) === true) {
        return $activeOrganisation;  // ← Dead code! $activeOrganisation is never string.
    }
    
    return '';
}
```

**Fix: Remove dead code and simplify**
```php
public function getActiveOrg(?Organisation $activeOrganisation): string
{
    if ($activeOrganisation === null) {
        return '';
    }
    
    return $activeOrganisation->getUuid();
}
```

## Fix Category 8: InvalidArgument

### Problem: Wrong type passed to method

**Error:**
```
ERROR: InvalidArgument - lib/Service/Object/ValidateObject.php:1088:85
Argument 2 of Opis\JsonSchema\Resolvers\FormatResolver::register expects Opis\JsonSchema\Format, but 'bsn' provided
```

**Bad Code:**
```php
$validator->parser()->getFormatResolver()->register(
    type: 'string',
    format: 'bsn',  // ← Should be Format object, not string!
    resolver: new BsnFormat()
);
```

**Fix: Check the library documentation**
```php
// Option A: Different parameter order (check docs)
$validator->parser()->getFormatResolver()->register(
    'string',
    'bsn',
    new BsnFormat()
);

// Option B: Create Format object
$validator->parser()->getFormatResolver()->register(
    type: 'string',
    format: new Format('bsn', new BsnFormat())
);
```

## Testing Your Fixes

After each fix:

```bash
# 1. Check Psalm
composer psalm

# 2. Run linters
composer cs:check

# 3. Run unit tests
composer test:unit

# 4. Test in Docker
docker exec -u 33 master-nextcloud-1 bash -c 'cd /var/www/html/apps-extra/openregister && ./vendor/bin/phpunit'

# 5. Check application logs
docker logs -f master-nextcloud-1 | grep 'OpenRegister'
```

## Systematic Fix Workflow

### Step 1: Pick a file from the high-priority list
```bash
# Files with most issues:
# - lib/Service/ObjectService.php (~100 errors)
# - lib/Service/Object/SaveObjects.php (~80 errors)
# - lib/Service/Object/ValidateObject.php (~40 errors)
```

### Step 2: Run Psalm on that file only
```bash
./vendor/bin/psalm --file=lib/Service/ObjectService.php
```

### Step 3: Group errors by type
- Count UndefinedMethod errors
- Count InvalidNamedArgument errors
- etc.

### Step 4: Fix all errors of one type
- Example: Fix all InvalidNamedArgument errors first
- This creates a consistent pattern

### Step 5: Test the file
```bash
# Run Psalm again
./vendor/bin/psalm --file=lib/Service/ObjectService.php

# Run tests related to that file
./vendor/bin/phpunit --filter ObjectService
```

### Step 6: Commit with meaningful message
```bash
git add lib/Service/ObjectService.php
git commit -m "fix(ObjectService): Remove invalid named arguments rbac and multi

- Removed 'rbac' parameter from RegisterMapper::find() calls
- Removed 'multi' parameter from SchemaMapper::find() calls  
- These parameters were removed in previous refactoring

Fixes: 20 InvalidNamedArgument Psalm errors"
```

## Common Patterns and Solutions

### Pattern 1: Method signature changed
**Symptoms:** InvalidNamedArgument + UndefinedVariable

**Solution:** Update all call sites to match new signature

### Pattern 2: Refactoring incomplete
**Symptoms:** UndefinedMethod + InvalidNamedArgument

**Solution:** Finish the refactoring or revert it

### Pattern 3: Over-defensive coding
**Symptoms:** TypeDoesNotContainNull + TypeDoesNotContainType

**Solution:** Trust your type hints and remove unnecessary checks

### Pattern 4: Missing dependency injection
**Symptoms:** UndefinedThisPropertyFetch

**Solution:** Add property and constructor parameter

### Pattern 5: Dead code from refactoring
**Symptoms:** NoValue

**Solution:** Remove the dead code

## Estimated Time per Issue Type

| Issue Type | Avg Time | Bulk Fix? |
|------------|----------|-----------|
| TypeDoesNotContainNull | 30 sec | Yes |
| InvalidNamedArgument | 1-2 min | Partially |
| UndefinedVariable | 2-5 min | No |
| UndefinedMethod | 5-15 min | No |
| TypeDoesNotContainType | 2-3 min | Partially |
| NoValue | 1-2 min | No |
| UndefinedThisPropertyFetch | 5-10 min | No |
| InvalidArgument | 3-5 min | No |

## Automation Scripts

### Script 1: Remove unnecessary null coalescing for typed arrays

```bash
#!/bin/bash
# remove-unnecessary-null-coalesce.sh

# This script helps identify (not auto-fix) unnecessary ?? [] patterns
# Review each match manually before changing!

echo "Finding potential unnecessary null coalescing..."
grep -rn "?? \[\]" lib/ | while read line; do
    echo "Review: $line"
done
```

### Script 2: Find all invalid named arguments

```bash
#!/bin/bash
# find-invalid-named-args.sh

echo "Finding calls with 'rbac:' parameter..."
grep -rn "rbac:" lib/

echo ""
echo "Finding calls with 'multi:' parameter..."
grep -rn "multi:" lib/

echo ""
echo "Finding calls with 'extend:' parameter..."
grep -rn "extend:" lib/
```

### Script 3: Progress tracker

```bash
#!/bin/bash
# psalm-progress.sh

echo "Running Psalm and counting errors..."
./vendor/bin/psalm 2>&1 | tee psalm-current.txt

ERRORS=$(grep "errors found" psalm-current.txt | awk '{print $1}')
echo ""
echo "Current error count: $ERRORS"
echo "Target: 0"
echo "Remaining: $ERRORS"
```

## Need Help?

1. **Check Psalm documentation:** Each error has a link (e.g., https://psalm.dev/022)
2. **Search codebase for similar patterns:** `grep -r "similarMethod" lib/`
3. **Check git history:** `git log -p -- path/to/file.php`
4. **Ask in team chat:** Share the specific Psalm error message

## Success Criteria

Your fixes are good when:

1. ✅ Psalm errors decrease
2. ✅ No new linter errors introduced
3. ✅ Unit tests still pass
4. ✅ Manual testing shows no regressions
5. ✅ Code is simpler and more maintainable

## Next Steps

1. Start with auto-fixable issues (run the commands at the top)
2. Pick the highest priority file (ObjectService.php)
3. Fix one issue category at a time
4. Test frequently
5. Commit small, focused changes
6. Update PSALM_ANALYSIS.md as you progress

Good luck! 🚀

