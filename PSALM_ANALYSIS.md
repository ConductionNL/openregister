# Psalm Static Analysis Report for OpenRegister

**Generated:** December 15, 2025  
**Psalm Version:** 5.26.1  
**Error Level:** 4 (out of 8, where 1 is most strict)  
**Type Coverage:** 87.5284% of the codebase

## Executive Summary

- **642 errors found** (blocking issues)
- **1,156 other issues** (info-level, warnings)
- **16 issues** can be automatically fixed by Psalm
- **Analysis time:** 34.01 seconds
- **Memory usage:** 359.518MB

## Issue Categories Breakdown

### 1. TypeDoesNotContainNull (~150+ occurrences)

**Description:** Variables that are known to never be null are being checked with null coalescing operators ('??').

**Example:**
```php
foreach ($savedObjects ?? [] as $obj) {
    // $savedObjects is already typed as non-nullable array
}
```

**Impact:** Medium - Code is overly defensive and creates confusion about actual types.

**Fix Strategy:**
- Remove unnecessary null coalescing operators
- Update docblocks to accurately reflect types
- Use proper type hints in method signatures

### 2. UndefinedMethod (~80+ occurrences)

**Description:** Calling methods that don't exist on mapper or handler classes.

**Common Examples:**
- `ObjectEntityMapper::find()` - doesn't exist
- `ObjectEntityMapper::findAll()` - doesn't exist
- `ObjectEntityMapper::findBySchema()` - doesn't exist
- `ObjectEntityMapper::countAll()` - doesn't exist
- `ObjectEntityMapper::searchObjects()` - doesn't exist
- `RenderObject::renderEntities()` - doesn't exist
- `RelationHandler::getContracts()` - doesn't exist

**Impact:** HIGH - These are actual bugs that will cause runtime errors.

**Fix Strategy:**
- Verify these methods exist or implement them
- Check for typos in method names
- Ensure proper inheritance and trait usage
- Review recent refactoring that may have removed methods

### 3. InvalidNamedArgument (~60+ occurrences)

**Description:** Using named parameters that don't exist in method signatures.

**Common Examples:**
- `rbac: false` - parameter doesn't exist
- `multi: false` - parameter doesn't exist
- `extend: ...` - parameter doesn't exist
- `register: ...` - parameter doesn't exist
- `resolver: ...` - parameter doesn't exist

**Impact:** HIGH - Will cause runtime errors in PHP 8.1+.

**Fix Strategy:**
- Review method signatures and fix parameter names
- Remove parameters that were removed during refactoring
- Update all call sites consistently

### 4. NoValue (~40+ occurrences)

**Description:** Dead code where all possible type paths have been invalidated.

**Example:**
```php
if (is_string($activeOrganisation) === true) {
    return $activeOrganisation; // NoValue - $activeOrganisation is never string
}
```

**Impact:** Medium - This is dead code that should be removed.

**Fix Strategy:**
- Remove unreachable code blocks
- Fix type annotations to match actual usage
- Simplify conditional logic

### 5. UndefinedVariable (~30+ occurrences)

**Description:** Variables used before being defined.

**Common Example:**
```php
multi: $multi // $multi is never defined
```

**Impact:** HIGH - Will cause runtime notices/errors.

**Fix Strategy:**
- Initialize variables before use
- Fix parameter definitions in methods
- Check for copy-paste errors

### 6. TypeDoesNotContainType (~30+ occurrences)

**Description:** Type checks that are always false based on known types.

**Example:**
```php
if (is_array($activeOrganisation) === true) {
    // $activeOrganisation is Organisation|null, never array
}
```

**Impact:** Medium - Dead code and logic errors.

**Fix Strategy:**
- Remove impossible type checks
- Fix docblocks to reflect actual types
- Simplify conditional logic

### 7. InvalidArgument (~25+ occurrences)

**Description:** Passing wrong types to methods.

**Examples:**
- Passing string literal where object expected
- Passing array where string expected
- Type mismatches in method calls

**Impact:** HIGH - Will cause runtime type errors.

**Fix Strategy:**
- Add proper type casting
- Fix parameter types in docblocks
- Use correct data structures

### 8. UndefinedThisPropertyFetch (~15+ occurrences)

**Description:** Accessing properties that aren't defined in the class.

**Examples:**
- `$this->objectCacheService` - not defined in ObjectService
- `$this->logger` - not defined in some handlers

**Impact:** HIGH - Runtime errors.

**Fix Strategy:**
- Add missing property declarations
- Inject dependencies properly via constructor
- Check for recent refactoring mistakes

### 9. UnusedBaselineEntry (~30+ occurrences)

**Description:** Baseline contains entries for issues that no longer exist.

**Impact:** Low - These are good! Issues were fixed but baseline wasn't updated.

**Fix Strategy:**
- Regenerate baseline: `./vendor/bin/psalm --set-baseline=psalm-baseline.xml`

### 10. Other Issues

- **InvalidArrayOffset** - Accessing array keys that don't exist
- **TooFewArguments** / **TooManyArguments** - Wrong argument counts
- **InvalidCast** - Invalid type casts
- **ParadoxicalCondition** - Conditions that are always true/false
- **EmptyArrayAccess** - Accessing keys on potentially empty arrays
- **InvalidReturnType** - Return types don't match docblocks
- **UndefinedClass** - Classes that don't exist

## Priority Action Plan

### Phase 1: Critical Fixes (HIGH Priority - Required for Stability)

1. **Fix UndefinedMethod errors** (~80 issues)
   - Verify and implement missing mapper methods
   - Check ObjectEntityMapper, RelationHandler, RenderObject classes
   - Estimated effort: 2-3 days

2. **Fix InvalidNamedArgument errors** (~60 issues)
   - Review all method signatures
   - Remove or correct invalid named parameters
   - Estimated effort: 1-2 days

3. **Fix UndefinedVariable errors** (~30 issues)
   - Define missing variables
   - Fix parameter definitions
   - Estimated effort: 4-6 hours

4. **Fix UndefinedThisPropertyFetch errors** (~15 issues)
   - Add missing property declarations
   - Fix dependency injection
   - Estimated effort: 2-3 hours

5. **Fix InvalidArgument errors** (~25 issues)
   - Correct type mismatches
   - Add proper casting
   - Estimated effort: 4-6 hours

### Phase 2: Code Quality Improvements (MEDIUM Priority)

1. **Remove TypeDoesNotContainNull checks** (~150 issues)
   - Remove unnecessary '??' operators
   - Update type hints and docblocks
   - Estimated effort: 1 day
   - Can be partially automated

2. **Remove dead code (NoValue)** (~40 issues)
   - Remove unreachable code blocks
   - Simplify conditional logic
   - Estimated effort: 4-6 hours

3. **Fix TypeDoesNotContainType checks** (~30 issues)
   - Remove impossible conditions
   - Fix type annotations
   - Estimated effort: 3-4 hours

### Phase 3: Cleanup (LOW Priority)

1. **Regenerate baseline**
   - Run: `./vendor/bin/psalm --set-baseline=psalm-baseline.xml`
   - Removes ~30 unused baseline entries
   - Estimated effort: 5 minutes

2. **Let Psalm auto-fix issues**
   - Run: `./vendor/bin/psalm --alter --issues=InvalidReturnType,MismatchingDocblockReturnType,InvalidNullableReturnType,LessSpecificReturnType`
   - Fixes 16 issues automatically
   - Estimated effort: 10 minutes + testing

## Improving Psalm Level

The current error level is 4. To improve code quality, consider:

### Current Configuration

```xml
<psalm errorLevel="4" />
```

**Level 4** catches:
- Type mismatches
- Undefined methods/variables
- Invalid arguments
- Some docblock issues

### Recommendations

1. **Short-term:** Stay at level 4 until critical issues are fixed
2. **Medium-term:** Move to level 3 (catches more type inconsistencies)
3. **Long-term:** Move to level 2 (strict type checking)
4. **Ideal:** Level 1 (maximum strictness)

### Configuration Improvements

**Current suppressions to review:**

```xml
<issueHandlers>
    <UnusedClass errorLevel="suppress"/>
    <PossiblyUnusedMethod errorLevel="suppress"/>
    <UnusedMethod errorLevel="suppress"/>
    <UnusedProperty errorLevel="suppress"/>
    <UnusedVariable errorLevel="suppress"/>
    <RedundantCondition errorLevel="suppress"/>
    <InvalidDocblock errorLevel="suppress"/>
</issueHandlers>
```

**Recommendation:** After fixing critical issues, gradually enable these checks:

1. Enable `UnusedVariable` (find unused variables)
2. Enable `RedundantCondition` (find unnecessary checks)
3. Enable `PossiblyUnusedMethod` (find dead code)
4. Enable `InvalidDocblock` (improve documentation)

## Automation Opportunities

### 1. Auto-fixable Issues (16 issues)

```bash
cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister
./vendor/bin/psalm --alter --issues=InvalidReturnType,MismatchingDocblockReturnType,InvalidNullableReturnType,LessSpecificReturnType --dry-run
```

Review the changes, then apply:

```bash
./vendor/bin/psalm --alter --issues=InvalidReturnType,MismatchingDocblockReturnType,InvalidNullableReturnType,LessSpecificReturnType
```

### 2. TypeDoesNotContainNull Fixes

Many of these can be fixed with careful find-replace:

```bash
# Find patterns like: $variable ?? []
# Where $variable is never null
# Replace with: $variable
```

### 3. Baseline Regeneration

```bash
./vendor/bin/psalm --set-baseline=psalm-baseline.xml
```

## Testing Strategy

After each fix category:

1. **Run Psalm:** `composer psalm`
2. **Run PHPUnit:** `composer test:unit`
3. **Run in Docker:** `composer test:docker`
4. **Check logs:** Verify no new runtime errors
5. **Manual testing:** Test affected features in browser

## Estimated Total Effort

| Phase | Effort | Priority |
|-------|--------|----------|
| Phase 1 (Critical) | 4-6 days | HIGH |
| Phase 2 (Quality) | 2-3 days | MEDIUM |
| Phase 3 (Cleanup) | 1 hour | LOW |
| **Total** | **6-9 days** | |

## Files with Most Issues

Based on the output, these files need the most attention:

1. **lib/Service/ObjectService.php** - ~100+ errors
   - UndefinedMethod
   - InvalidNamedArgument
   - UndefinedVariable
   - UndefinedThisPropertyFetch

2. **lib/Service/Object/SaveObjects.php** - ~80+ errors
   - Mostly TypeDoesNotContainNull
   - NoValue issues

3. **lib/Service/Object/ValidateObject.php** - ~40+ errors
   - InvalidArgument
   - TypeDoesNotContainType
   - NoValue

4. **lib/Service/Object/QueryHandler.php** - ~30+ errors
   - UndefinedMethod
   - InvalidArgument

5. **lib/Service/Object/PermissionHandler.php** - ~20+ errors
   - TypeDoesNotContainType
   - NoValue

6. **lib/Service/SettingsService.php** - ~15+ errors
   - Various types

7. **lib/Db/ObjectEntityMapper.php** - Issues with method definitions

## Benefits of Fixing These Issues

1. **Reliability:** Prevent runtime errors and crashes
2. **Type Safety:** Better IDE support and autocomplete
3. **Maintainability:** Easier to understand code flow
4. **Performance:** Remove dead code paths
5. **Documentation:** Accurate type information
6. **Debugging:** Catch errors at development time vs production
7. **Refactoring:** Safer code changes with type checking

## Next Steps

1. **Immediate:** Review this document and prioritize fixes
2. **Today:** Fix UndefinedMethod errors (highest impact)
3. **This week:** Complete Phase 1 critical fixes
4. **Next week:** Phase 2 quality improvements
5. **Ongoing:** Enable more Psalm checks incrementally

## Tools and Resources

- **Psalm Documentation:** https://psalm.dev
- **Psalm Playground:** https://psalm.dev/r (test fixes)
- **Issue Reference:** Each error links to documentation (e.g., psalm.dev/022)
- **Auto-fix capability:** Use `--alter` flag
- **IDE Integration:** PHPStorm, VSCode with Psalm plugin

## Conclusion

The OpenRegister app has **642 errors** that need attention, primarily:

1. Missing or incorrectly called methods (HIGH priority)
2. Invalid named parameters (HIGH priority)
3. Undefined variables (HIGH priority)
4. Overly defensive null checks (MEDIUM priority)
5. Dead code (MEDIUM priority)

The good news:
- Type inference is good (87.5%)
- Many issues are repetitive and can be batch-fixed
- 16 issues can be auto-fixed
- ~30 issues were already fixed (unused baseline entries)

**Recommended immediate action:** Focus on Phase 1 to eliminate critical runtime errors, then gradually improve code quality with Phase 2 and 3.

