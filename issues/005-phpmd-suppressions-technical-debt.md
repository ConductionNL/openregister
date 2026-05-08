# Issue 005: PHPMD Suppressions Technical Debt

**Status:** 📋 Open
**Priority:** 🟢 Low
**Category:** 🔧 Technical Debt
**Effort:** ⏱️ 16-32h
**Created:** 2026-01-05
**Target:** Reduce code complexity and remove unnecessary suppressions

---

## Problem Statement

During the PHP linting cleanup (PHPCS, PHPMD, Psalm), ~1,650 PHPMD warnings were addressed using `@SuppressWarnings` annotations. While this makes the linting pass, it doesn't fix the underlying code complexity issues.

## Current Situation

### Suppression Summary (Total: 1,651)

| Suppression Type | Count | Priority | Notes |
|-----------------|-------|----------|-------|
| CyclomaticComplexity | 388 | 🔴 High | Complex branching logic |
| NPathComplexity | 231 | 🔴 High | Too many execution paths |
| BooleanArgumentFlag | 212 | 🟢 Low | Often necessary for optional behavior |
| UnusedFormalParameter | 202 | 🟡 Medium | Interface conformance / future use |
| ExcessiveMethodLength | 186 | 🔴 High | Methods >100 lines |
| UnusedPrivateMethod | 85 | 🟡 Medium | May indicate dead code |
| ExcessiveClassComplexity | 76 | 🟡 Medium | Complex classes |
| CouplingBetweenObjects | 47 | 🟢 Low | DI pattern causes high coupling |
| StaticAccess | 43 | 🟢 Low | Nextcloud patterns require this |
| ElseExpression | 39 | 🟢 Low | Quick wins, improve readability |
| TooManyPublicMethods | 35 | 🟢 Low | Service/controller pattern |
| ExcessiveClassLength | 35 | 🟡 Medium | Large classes |
| ExcessiveParameterList | 30 | 🟢 Low | DI pattern |
| TooManyFields | 15 | ✅ OK | Entity classes (architecturally correct) |
| TooManyMethods | 12 | 🟢 Low | Handler pattern |
| BooleanGetMethodName | 4 | 🟢 Low | `getActive()` vs `isActive()` |
| UnusedPrivateField | 3 | 🟡 Medium | May indicate dead code |
| ExcessivePublicCount | 3 | 🟢 Low | Service API pattern |
| UnusedLocalVariable | 2 | 🟡 Medium | Code cleanup needed |
| LongVariable | 2 | 🟢 Low | Descriptive naming is good |
| ExitExpression | 1 | 🟡 Medium | Avoid exit in library code |

### Files with Most Suppressions (Top 20)

These files should be prioritized for refactoring:

| File | Suppressions | Priority |
|------|-------------|----------|
| `lib/Service/Object/SaveObject.php` | 46 | 🔴 High |
| `lib/Service/Object/SaveObjects.php` | 42 | 🔴 High |
| `lib/Db/MagicMapper.php` | 40 | 🔴 High |
| `lib/Db/ObjectEntityMapper.php` | 37 | 🔴 High |
| `lib/Service/FileService.php` | 34 | 🟡 Medium |
| `lib/Service/ObjectService.php` | 29 | 🔴 High |
| `lib/Service/Configuration/ImportHandler.php` | 29 | 🟡 Medium |
| `lib/Controller/ObjectsController.php` | 29 | 🟡 Medium |
| `lib/Service/ImportService.php` | 28 | 🟡 Medium |
| `lib/Service/ConfigurationService.php` | 28 | 🟡 Medium |
| `lib/Service/SchemaService.php` | 25 | 🟡 Medium |
| `lib/Db/SchemaMapper.php` | 23 | 🟡 Medium |
| `lib/Service/Object/RenderObject.php` | 22 | 🟡 Medium |
| `lib/Service/SettingsService.php` | 21 | 🟢 Low |
| `lib/Service/Index/SetupHandler.php` | 21 | 🟢 Low |
| `lib/Db/ObjectEntity/BulkOperationsHandler.php` | 20 | 🟡 Medium |
| `lib/Service/Object/ValidateObject.php` | 19 | 🟡 Medium |
| `lib/Service/TextExtractionService.php` | 18 | 🟢 Low |
| `lib/Service/OrganisationService.php` | 18 | 🟢 Low |
| `lib/Service/Object/QueryHandler.php` | 18 | 🟡 Medium |

### Entity Classes (TooManyFields - Acceptable)

These suppressions are **architecturally acceptable** - domain entities naturally have many fields:

- `lib/Db/Agent.php`
- `lib/Db/Application.php`
- `lib/Db/AuditTrail.php`
- `lib/Db/Chunk.php`
- `lib/Db/Configuration.php`
- `lib/Db/Endpoint.php`
- `lib/Db/ObjectEntity.php`
- `lib/Db/Organisation.php`
- `lib/Db/Register.php`
- `lib/Db/Schema.php`
- `lib/Db/SearchTrail.php`
- `lib/Db/Webhook.php`

## High-Priority Refactoring Candidates

Methods that would benefit most from refactoring:

### 1. SaveObject.php (46 suppressions)
- Core object saving logic with many validation paths
- Break into: validation, transformation, persistence handlers

### 2. SaveObjects.php (42 suppressions)
- Bulk operation handler with complex batch logic
- Break into: smaller chunk processors

### 3. MagicMapper.php (40 suppressions)
- Dynamic query builder with many conditions
- Consider Strategy pattern for different query types

### 4. ObjectEntityMapper.php (37 suppressions)
- Complex database operations with many filters
- Extract filter builders into separate classes

### 5. ObjectService.php (29 suppressions)
- Orchestration service with many dependencies
- Consider CQRS pattern for read/write separation

## Proposed Solutions

### Short Term (Current Implementation) ✅
Use `@SuppressWarnings` to pass linting while maintaining code functionality.

### Medium Term
1. Break down large methods into smaller, focused functions
2. Use early returns to reduce nesting depth
3. Extract complex conditionals into helper methods
4. Convert else clauses to early returns where appropriate
5. Remove unused private methods/fields

### Long Term
1. Consider using the Command pattern for complex operations
2. Implement Strategy pattern for variant behavior
3. Use Builder pattern for complex object construction
4. Consider CQRS for read/write separation in services

## Refactoring Approach

When refactoring a suppressed method:

1. **Identify the suppression reason** (complexity, length, etc.)
2. **Extract logical units** into private helper methods
3. **Use early returns** instead of nested if/else
4. **Remove the suppression** after refactoring
5. **Run tests** to verify behavior unchanged
6. **Run PHPMD** to verify suppression can be removed

### Example: ElseExpression Fix

```php
// Before (with ElseExpression):
if ($condition) {
    return $valueA;
} else {
    return $valueB;
}

// After (early return):
if ($condition) {
    return $valueA;
}
return $valueB;
```

### Example: Method Length Reduction

```php
// Before: 150 line method
public function processData($data) {
    // 150 lines of mixed logic
}

// After: Composed smaller methods
public function processData($data) {
    $validated = $this->validateData($data);
    $transformed = $this->transformData($validated);
    return $this->persistData($transformed);
}
```

### Example: Cyclomatic Complexity Reduction

```php
// Before: Complex switch/if chain
public function handleType($type, $data) {
    if ($type === 'A') { /* 20 lines */ }
    elseif ($type === 'B') { /* 20 lines */ }
    elseif ($type === 'C') { /* 20 lines */ }
    // ...
}

// After: Strategy pattern
private array $handlers = [
    'A' => TypeAHandler::class,
    'B' => TypeBHandler::class,
    'C' => TypeCHandler::class,
];

public function handleType($type, $data) {
    $handler = $this->container->get($this->handlers[$type]);
    return $handler->handle($data);
}
```

## Implementation Plan

1. [ ] Start with `ElseExpression` fixes (39 occurrences) - Quick wins
2. [ ] Address `UnusedPrivateMethod` (85 occurrences) - Remove dead code
3. [ ] Refactor top 5 files by suppression count
4. [ ] Focus on `ExcessiveMethodLength` in critical paths
5. [ ] Tackle `CyclomaticComplexity` in core services
6. [ ] Document patterns to prevent future accumulation

## Testing Strategy

- All existing unit tests must pass after refactoring
- Add new tests for extracted methods where coverage is low
- Run full PHPMD analysis after each batch of changes
- Verify no regressions in integration tests

## Real Bugs Found During Analysis

These were fixed immediately (not suppressed):

1. **`lib/Service/Object/PerformanceHandler.php:193`**
   - UndefinedVariable `$extendArray` should have been `$extend`

2. **Unused Private Fields** - Properties stored under different names:
   - `FileService.php` - 5 property name mismatches fixed
   - `CacheSettingsHandler.php` - 1 property name mismatch fixed

3. **Missing Property Definitions** (Psalm errors fixed):
   - `SettingsService.php` - 4 missing property definitions
   - `ImportService.php` - 1 missing property definition
   - `ConfigurationService.php` - 1 missing property definition
   - Multiple File handlers - property name mismatches

## Metrics to Track

| Metric | Before | Target | Current |
|--------|--------|--------|---------|
| Total Suppressions | 1,651 | <500 | 1,651 |
| CyclomaticComplexity | 388 | <100 | 388 |
| ExcessiveMethodLength | 186 | <50 | 186 |
| UnusedPrivateMethod | 85 | 0 | 85 |

## References

- PHPMD documentation: https://phpmd.org/
- Clean Code principles: https://clean-code-developer.com/
- Nextcloud coding standards: https://docs.nextcloud.com/server/latest/developer_manual/

## Status Updates

| Date | Update |
|------|--------|
| 2026-01-05 | Issue created. ~1,650 suppressions added to pass PHPMD. |
| 2026-01-05 | Fixed real bug in PerformanceHandler.php (UndefinedVariable) |
| 2026-01-05 | Fixed property name mismatches in multiple files |
| 2026-01-05 | All linters now pass: PHPMD 0, PHPCS 0, Psalm 0 |

## Discussion

The suppressions are a pragmatic short-term solution. The codebase is functional and the complexity is largely inherent to the domain (OpenRegister handles complex data operations with many configuration options).

**Priority should be given to:**
1. `UnusedPrivateMethod` (85) - These may be dead code
2. `ExcessiveMethodLength` (186) - Hardest to maintain
3. `CyclomaticComplexity` (388) - Hardest to test
4. `ElseExpression` (39) - Quick wins, improve readability

**Low priority (acceptable in this architecture):**
- `TooManyFields` on entity classes (architecturally correct)
- `BooleanArgumentFlag` (often necessary for optional behavior)
- `ExcessiveParameterList` on handlers (DI pattern)
- `CouplingBetweenObjects` (DI causes high coupling by design)
- `StaticAccess` (Nextcloud patterns require this)

---

**Last Updated:** 2026-01-05
