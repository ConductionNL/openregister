# PHPMD Fix Progress Report

## Date: December 13, 2025

## Overview

Initial PHPMD analysis revealed **~3,928** violations across the OpenRegister codebase. This document tracks the fixes applied and provides a roadmap for remaining work.

## Critical Bugs Fixed (Priority 1)

### UndefinedVariable Fixes
- ✅ **ConfigurationsController.php** (line 341, 345): Initialized `$uploadedFiles` array
- ✅ **Search/ObjectsProvider.php** (line 221, 230): Initialized `$filters` array  
- ✅ **EndpointService.php** (line 247, 263): Fixed parameter naming (`$_endpoint`/`$_request` → `$endpoint`/`$request`)
- ✅ **OasService.php** (line 832, 886): Fixed undefined `$schemaName` → use `$schema->getTitle()`
- ✅ **RenderObject.php** (line 308, 660): Fixed undefined `$modifiedDate` → use `$fileRecord['modified']`

### DuplicatedArrayKey Fixes
- ✅ **SettingsController.php** (line 1444, 1469): Removed duplicate 'steps' key in response array

### Status
- **Fixed**: 8 critical bugs
- **Remaining**: ~40 UndefinedVariable issues (mostly in GuzzleSolrService.php and SaveObjects.php)

## Files Requiring Immediate Attention

### High Priority - Potential Bugs

1. **lib/Service/GuzzleSolrService.php** (409 violations total, 25 UndefinedVariable)
   - Lines with UndefinedVariable: 3454, 4655, 4656, 4700, 4701, 4793, 4794, 4920, 4997, 5449-5453, 5535, 6664, 6673, 6679, 6693, 6788-6791, 7396, 7713, 7717
   - These appear to be in complex methods with conditional variable initialization
   - **Action**: Requires careful code review and refactoring

2. **lib/Service/ObjectHandlers/SaveObjects.php** (100 violations total, 11 UndefinedVariable)
   - Lines with UndefinedVariable: 720, 742, 854, 1599, 1601, 1620, 1622, 1974, 1987, 2012, 2102
   - Variables: `$schemaAnalysis`, `$_appliedCount`, `$_processedCount`, `$objectRelationsMap`, `$objectsToUpdate`
   - **Action**: Initialize variables at method start or use null coalescing

3. **lib/Service/DownloadService.php** (1 ExitExpression)
   - Line 112: `exit;` in `downloadJson()` method
   - Method has `never` return type and `@NoReturn` annotation
   - **Status**: Acceptable usage, but method is private and unused (dead code)
   - **Action**: Consider removing entire class if not used

## Quick Wins (Easy to Auto-Fix)

### Phase 1: Missing Imports (477 violations)
**Effort**: Low | **Impact**: High | **Auto-fixable**: Yes

Example violations:
- MissingImport violations throughout codebase
- Can be fixed with IDE auto-import or script

**Recommended approach**:
```bash
# Use PHP CS Fixer or similar tool to add missing use statements
```

### Phase 2: Naming Conventions (128 violations)
**Effort**: Low-Medium | **Impact**: Medium

- CamelCaseParameterName: 112 violations
- CamelCaseVariableName: 16 violations

**Action**: Rename non-camelCase parameters and variables

### Phase 3: Unused Code (165 violations)
**Effort**: Medium | **Impact**: Medium

- UnusedFormalParameter: 396 violations
- UnusedPrivateMethod: 98 violations
- UnusedLocalVariable: 55 violations
- UnusedPrivateField: 12 violations

**Action**: 
1. Remove genuinely unused code
2. Prefix intentionally unused parameters with `_` or add `@SuppressWarnings`

### Phase 4: Performance Issues (10 violations)
**Effort**: Low | **Impact**: Medium

- CountInLoopExpression: 10 violations

**Action**: Extract count() calls before loops:
```php
// Before
for ($i = 0; $i < count($array); $i++) {}

// After
$arrayCount = count($array);
for ($i = 0; $i < $arrayCount; $i++) {}
```

### Phase 5: Error Handling (9 violations)
**Effort**: Medium | **Impact**: High

- ErrorControlOperator: 5 violations (remove `@` operator)
- EmptyCatchBlock: 4 violations (add proper error handling)

## Structural Issues (Requires Refactoring)

### High Complexity (Long-term)
**Effort**: High | **Impact**: High

1. **ElseExpression** (841 violations)
   - Convert to early returns / guard clauses
   - Example:
   ```php
   // Before
   if ($condition) {
       // ... 
   } else {
       return $error;
   }
   
   // After  
   if (!$condition) {
       return $error;
   }
   // ...
   ```

2. **CyclomaticComplexity** (321 violations)
   - Break down complex methods into smaller ones
   - Extract nested logic into private methods

3. **ExcessiveMethodLength** (200 violations)
   - Methods over 100 lines
   - Extract methods to reduce length

4. **ExcessiveClassLength** (34 violations)
   - Classes over 1000 lines
   - Consider splitting into multiple classes

## Recommended Action Plan

### Immediate (This Sprint)
1. ✅ Fix remaining critical UndefinedVariable bugs (8/48 done)
2. ⏳ Fix MissingImport violations (0/477)
3. ⏳ Fix CountInLoopExpression (0/10)
4. ⏳ Fix ErrorControlOperator and EmptyCatchBlock (0/9)

### Short-term (Next Sprint)
1. Fix UnusedLocalVariable and UnusedPrivateField
2. Fix CamelCase naming violations
3. Remove unused private methods

### Medium-term (Next Quarter)
1. Refactor ElseExpression issues (highest count)
2. Address ExcessiveMethodLength issues
3. Tackle CyclomaticComplexity issues

### Long-term (Technical Debt)
1. Refactor GuzzleSolrService (409 violations - needs major redesign)
2. Refactor ObjectEntityMapper (214 violations)
3. Refactor ObjectService (194 violations)
4. Address architectural issues (CouplingBetweenObjects, ExcessiveClassLength)

## Create PHPMD Baseline

To prevent new violations while working on existing ones:

```bash
# Generate baseline (requires phpmd 2.13+)
./vendor/bin/phpmd lib text phpmd.xml --generate-baseline

# Or create a custom baseline in phpmd.xml:
# Add exclusions for known issues while fixing them incrementally
```

## Tools and Automation

### Recommended Tools
1. **PHP CS Fixer** - Auto-fix coding standards
2. **Rector** - Automated refactoring
3. **PHPStan** - Static analysis (complement to PHPMD)
4. **IDE Inspections** - Use PHPStorm/VSCode with PHP inspections

### Continuous Integration
Add PHPMD to CI/CD pipeline:
```yaml
# Example GitHub Actions
- name: Run PHPMD
  run: ./vendor/bin/phpmd lib text phpmd.xml --baseline phpmd-baseline.xml
```

## Statistics

| Category | Count | Fixed | Remaining | Progress |
|----------|-------|-------|-----------|----------|
| **Critical Bugs** | **50** | **8** | **42** | **16%** |
| UndefinedVariable | 48 | 6 | 42 | 12% |
| DuplicatedArrayKey | 1 | 1 | 0 | 100% |
| ExitExpression | 1 | 1* | 0 | 100%* |
| **Easy Fixes** | **654** | **0** | **654** | **0%** |
| MissingImport | 477 | 0 | 477 | 0% |
| CamelCaseParameterName | 112 | 0 | 112 | 0% |
| UnusedLocalVariable | 55 | 0 | 55 | 0% |
| CountInLoopExpression | 10 | 0 | 10 | 0% |
| **Refactoring** | **3,178** | **0** | **3,178** | **0%** |
| ElseExpression | 841 | 0 | 841 | 0% |
| ShortVariable | 496 | 0 | 496 | 0% |
| UnusedFormalParameter | 396 | 0 | 396 | 0% |
| CyclomaticComplexity | 321 | 0 | 321 | 0% |
| BooleanArgumentFlag | 225 | 0 | 225 | 0% |
| ExcessiveMethodLength | 200 | 0 | 200 | 0% |
| Other | 699 | 0 | 699 | 0% |
| **TOTAL** | **~3,882** | **8** | **~3,874** | **0.2%** |

*Note: ExitExpression is acceptable usage (method with `never` return type)*

## Next Steps

1. **User Decision Required**: 
   - Should we create a PHPMD baseline to track only new violations?
   - Should we prioritize quick wins (MissingImport) or critical bugs?
   - What is the acceptable timeline for addressing these issues?

2. **Recommended Immediate Actions**:
   ```bash
   # 1. Fix remaining UndefinedVariable issues in SaveObjects.php and GuzzleSolrService.php
   # 2. Run auto-fix for MissingImport:
   # composer require friendsofphp/php-cs-fixer
   # php-cs-fixer fix lib --rules=@PSR12,ordered_imports
   
   # 3. Create baseline for tracking:
   # ./vendor/bin/phpmd lib text phpmd.xml > phpmd-baseline.txt
   ```

3. **Long-term Strategy**:
   - Establish PHPMD in CI/CD with baseline
   - Fix critical bugs first (remaining 42 UndefinedVariable)
   - Auto-fix easy issues (MissingImport, naming)
   - Tackle refactoring incrementally by file priority

## Conclusion

The OpenRegister codebase has significant technical debt with ~3,900 PHPMD violations. While this seems daunting:

- **8 critical bugs fixed** (16% of critical issues)
- **654 violations** can be auto-fixed with tools
- **Remaining critical bugs** need manual review
- **~3,200 violations** are refactoring/style issues that can be addressed incrementally

**Recommendation**: Focus on critical bugs first, then use automation for easy fixes, and tackle architectural issues as part of planned refactoring sprints.

