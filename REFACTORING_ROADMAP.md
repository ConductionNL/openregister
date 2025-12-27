# Code Quality Refactoring Roadmap - OpenRegister

**Date:** December 2024  
**Status:** Analysis Complete - Implementation Roadmap

## Executive Summary

PHPMD analysis revealed **60+ methods** exceeding 100 lines and **numerous high-complexity methods**. This document provides a prioritized roadmap for refactoring based on:
- **Business Impact**: Core services vs. peripheral features
- **Complexity Metrics**: Cyclomatic complexity + NPath complexity  
- **Maintainability**: Frequency of changes + developer pain points

## Priority Matrix

### 🔴 CRITICAL (High Complexity + High Impact)

These methods have extreme complexity metrics and are in core business logic paths:

| Method | Lines | Cyclomatic | NPath | Priority | Reason |
|--------|-------|------------|-------|----------|--------|
| `SchemaService::comparePropertyWithAnalysis()` | 173 | 36 | 110,376 | **P0** | Schema analysis core logic |
| `SchemaService::recommendPropertyType()` | 110 | 37 | 47,040 | **P0** | Type recommendation engine |
| `ObjectService::findAll()` | 103 | 21 | 20,736 | **P0** | Primary object retrieval method |
| `ObjectService::saveObject()` | 160 | 24 | 13,824 | **P0** | Primary object persistence method |
| `SchemaService::mergePropertyAnalysis()` | ~90 | 20 | 38,880 | **P1** | Property analysis aggregation |
| `SchemaService::generateSuggestions()` | 110 | 18 | 15,554 | **P1** | Schema suggestion generator |

### 🟡 HIGH (Moderate Complexity or High Length)

| Method | Lines | Cyclomatic | NPath | Priority | Reason |
|--------|-------|------------|-------|----------|--------|
| `SettingsService::massValidateObjects()` | 175 | 10 | 216 | **P1** | Batch validation |
| `ObjectService::searchObjectsPaginated()` | ~100 | 16 | N/A | **P2** | Search functionality |
| `SchemaService::detectStringFormat()` | ~80 | 16 | 12,288 | **P2** | Format detection |
| `SettingsService::processJobsSerial()` | 117 | N/A | N/A | **P2** | Background job processing |

### 🟢 MEDIUM (Controllers & Peripheral)

| Category | Count | Priority | Notes |
|----------|-------|----------|-------|
| Controller methods | 25+ | **P3** | Can tolerate higher complexity |
| Import/Export handlers | 10+ | **P3** | Complex but stable |
| Background jobs | 5+ | **P4** | Run infrequently |
| DI Container (`Application::register()`) | 1 (483 lines) | **P4** | Auto-generated style, stable |

## Detailed Refactoring Plans

### P0-1: SchemaService::comparePropertyWithAnalysis()

**Current State:**
- **173 lines**, **Cyclomatic Complexity: 36**, **NPath: 110,376**
- Compares discovered property patterns against schema definitions
- Returns detailed analysis of mismatches and recommendations

**Refactoring Strategy:**
```php
// Extract comparison logic into focused methods:
private function comparePropertyType(array $existing, array $discovered): array
private function comparePropertyFormat(array $existing, array $discovered): array  
private function comparePropertyConstraints(array $existing, array $discovered): array
private function comparePropertyEnum(array $existing, array $discovered): array
private function buildComparisonResult(array $comparisons): array
```

**Expected Outcome:**
- Main method: ~40 lines (orchestration only)
- Complexity: ~12
- NPath: < 200
- Improved testability (can test each comparison type independently)

### P0-2: SchemaService::recommendPropertyType()

**Current State:**
- **110 lines**, **Cyclomatic Complexity: 37**, **NPath: 47,040**
- Analyzes property usage patterns to recommend JSON Schema types
- Handles string formats, numeric ranges, enum detection, object/array structures

**Refactoring Strategy:**
```php
// Extract type-specific recommendation logic:
private function recommendStringType(array $analysis): array
private function recommendNumericType(array $analysis): array
private function recommendBooleanType(array $analysis): array
private function recommendObjectType(array $analysis): array
private function recommendArrayType(array $analysis): array
private function recommendEnumType(array $analysis): ?array
private function selectBestType(array $typeRecommendations): string
```

**Expected Outcome:**
- Main method: ~30 lines (delegates to type handlers)
- Complexity: ~8
- NPath: < 100
- Each type handler can be tested independently

### P0-3: ObjectService::findAll()

**Current State:**
- **103 lines**, **Cyclomatic Complexity: 21**, **NPath: 20,736**
- Primary method for retrieving multiple objects with filtering
- Handles register/schema filtering, RBAC, multitenancy, pagination

**Refactoring Strategy:**
```php
// Extract filter-building and query execution:
private function buildFindAllFilters(array $params): array
private function applyRbacFilters(array $filters, bool $rbac): array
private function applyMultitenancyFilters(array $filters, bool $multitenancy): array
private function applySchemaRegistrationFilters(array $filters): array
private function executeFindAllQuery(array $filters, ?int $limit, ?int $offset): array
```

**Expected Outcome:**
- Main method: ~30-40 lines
- Complexity: ~10
- NPath: < 500
- Filter logic is testable in isolation

### P0-4: ObjectService::saveObject()

**Current State:**
- **160 lines**, **Cyclomatic Complexity: 24**, **NPath: 13,824**
- Primary method for persisting objects (CREATE/UPDATE)
- Handles UUID extraction, permission checking, cascading, validation, folder creation

**Current Strengths** (DON'T LOSE THESE):
- Already delegates to handlers (cascadingHandler, validateHandler, saveHandler, renderHandler)
- Linear flow with clear comments
- Good separation between concerns

**Refactoring Strategy:**
```php
// Extract repetitive/complex inline logic:
private function extractUuidFromObject(array|ObjectEntity $object, ?string $uuid): ?string
private function checkSavePermissions(?string $uuid, bool $rbac): void
private function ensureObjectFolder(?string $uuid): ?int
private function prepareObjectContext($register, $schema): void
private function restoreObjectContext(Register $register, Schema $schema): void
```

**Expected Outcome:**
- Main method: ~80-90 lines (still substantial but clearer)
- Complexity: ~15
- NPath: < 1000
- UUID/permission/folder logic can be tested independently

### P1-1: SettingsService::massValidateObjects()

**Current State:**
- **175 lines**, **Cyclomatic Complexity: 10**, **NPath: 216**
- Batch validation of all objects in a schema
- Progress tracking, error collection, statistics generation

**Refactoring Strategy:**
```php
// Extract batch processing and reporting:
private function initializeValidationBatch(Schema $schema): array
private function validateObjectBatch(array $objects, bool $collectErrors): array
private function generateValidationStatistics(array $results): array
private function formatValidationReport(array $stats, array $errors): array
```

**Expected Outcome:**
- Main method: ~60 lines
- Clearer separation between validation, reporting, and error handling

## Refactoring Patterns to Apply

### 1. Extract Method Pattern

**Before:**
```php
public function complexMethod($params) {
    // 50 lines of logic A
    // 50 lines of logic B  
    // 50 lines of logic C
}
```

**After:**
```php
public function complexMethod($params) {
    $resultA = $this->doLogicA($params);
    $resultB = $this->doLogicB($resultA);
    return $this->doLogicC($resultB);
}

private function doLogicA($params): ResultA { /* focused logic */ }
private function doLogicB(ResultA $input): ResultB { /* focused logic */ }
private function doLogicC(ResultB $input): Result { /* focused logic */ }
```

### 2. Replace Conditional with Polymorphism (for type checking)

**Before:**
```php
if ($type === 'string') {
    // 20 lines
} elseif ($type === 'number') {
    // 20 lines
} elseif ($type === 'object') {
    // 20 lines
}
```

**After:**
```php
$handler = $this->getTypeHandler($type);
return $handler->handle($data);
```

### 3. Introduce Parameter Object (for long parameter lists)

**Before:**
```php
public function method($a, $b, $c, $d, $e, $f, $g, $h, $i) { ... }
```

**After:**
```php
public function method(MethodParameters $params) { ... }
```

### 4. Strategy Pattern (for conditional logic)

**Before:**
```php
if ($mode === 'sync') { /* logic */ }
elseif ($mode === 'async') { /* logic */ }
elseif ($mode === 'batch') { /* logic */ }
```

**After:**
```php
$strategy = $this->getProcessingStrategy($mode);
return $strategy->execute($data);
```

## Implementation Guidelines

### Phase 1: Critical Methods (P0)
1. **Write comprehensive tests FIRST** for each method before refactoring
2. Use code coverage to ensure tests cover all branches
3. Refactor one method at a time
4. Run full test suite after each refactoring
5. Run PHPMD after each change to verify improvements

### Phase 2: High Priority Methods (P1)
1. Same process as Phase 1
2. Can be done in parallel by different developers

### Phase 3: Medium Priority (P2-P3)
1. Refactor as part of feature work in those areas
2. Don't refactor just for refactoring's sake

### Phase 4: Low Priority (P4)
1. Leave as-is unless causing actual problems
2. `Application::register()` is intentionally verbose (DI container registration)

## Testing Strategy

### Before Refactoring
```bash
# 1. Run existing tests
composer test:unit

# 2. Check current code coverage
composer test:coverage

# 3. Baseline PHPMD metrics
composer phpmd > phpmd-before.txt
```

### During Refactoring
```bash
# 1. Write tests for extracted methods
# 2. Verify existing tests still pass
# 3. Check PHPMD improvements
composer phpmd | grep "MethodName"
```

### After Refactoring
```bash
# 1. Full test suite
composer test:unit

# 2. Compare coverage (should be same or better)
composer test:coverage

# 3. Compare PHPMD metrics
composer phpmd > phpmd-after.txt
diff phpmd-before.txt phpmd-after.txt

# 4. Run full PHPQA
composer phpqa
```

## Success Metrics

### Per-Method Targets
- **Cyclomatic Complexity**: < 10 (was 36)
- **NPath Complexity**: < 200 (was 110,376)
- **Method Length**: < 100 lines (was 173)
- **Test Coverage**: > 80% branch coverage

### Overall Targets
- **Reduce** methods > 100 lines from 60+ to < 20
- **Reduce** methods with complexity > 15 from 20+ to < 5
- **Maintain** 100% of existing functionality
- **Increase** unit test coverage by 10%

## Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Breaking existing functionality | **High** | Comprehensive tests before refactoring |
| Introducing bugs | **High** | Small, incremental changes with tests |
| Performance regression | **Medium** | Benchmark critical paths before/after |
| Scope creep | **Medium** | Stick to refactoring - no feature additions |
| Time investment | **Medium** | Prioritize by business impact |

## Resources & Tools

- **PHPMD**: `composer phpmd` - Code quality analysis
- **PHPUnit**: `composer test:unit` - Unit testing
- **PHPCS**: `composer cs:check` - Code style
- **PHPStan/Psalm**: Static analysis (configure if needed)
- **PHPMetrics**: `composer phpqa` - Detailed metrics

## References

- Martin Fowler - Refactoring: Improving the Design of Existing Code
- Robert C. Martin - Clean Code
- [Nextcloud Developer Manual](https://docs.nextcloud.com/server/latest/developer_manual/)
- [PHP The Right Way - Best Practices](https://phptherightway.com/)

## Next Steps

1. ✅ **Review this document** with the team
2. **Create unit tests** for P0 methods (if not exist)
3. **Start with SchemaService::comparePropertyWithAnalysis()** (worst offender)
4. **Track progress** using GitHub issues/milestones
5. **Schedule regular refactoring time** (e.g., 20% of sprint capacity)

---

**Remember**: Refactoring is NOT about perfection. It's about making code **easier to understand, modify, and maintain**. Focus on the methods that cause the most developer pain.





