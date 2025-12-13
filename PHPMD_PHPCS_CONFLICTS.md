# PHPMD vs PHPCS Configuration Conflicts

## Date: December 13, 2025

This document identifies conflicts between the PHPMD and PHPCS configurations where the tools enforce contradictory rules or where one tool is significantly more strict than the other.

## Critical Conflicts (Already Resolved)

### ✅ 1. CamelCaseParameterName & CamelCaseVariableName
**Status**: RESOLVED

**Conflict**:
- **PHPCS** (lines 111-115): Allows leading underscores on variables (PrivateNoUnderscore severity=0)
- **PHPMD** (line 39): CamelCaseParameterName forbids leading underscores on parameters

**Codebase Convention**:
```php
// 27 methods use underscore-prefixed parameters for unused/config params
private function executeViewEndpoint(Endpoint $_endpoint, array $_request): array
public function find(..., bool $_rbac=true, bool $_multi=true): ObjectEntity

// Foreach loops use underscore-prefixed variables
foreach ($chunks as $_chunkIndex => $uuidChunk) { ... }
```

**Resolution**: 
- Removed `CamelCaseParameterName` from phpmd.xml (line 40)
- Removed `CamelCaseVariableName` from phpmd.xml (line 42)
- **Impact**: ~130 violations eliminated

---

## Active Conflicts (Require Decision)

### 🔴 2. Inline If Statements / Ternary Operators
**Status**: UNRESOLVED - CRITICAL

**Conflict**:
- **PHPCS** (line 52): `Squiz.PHP.DisallowInlineIf` - FORBIDS ternary operators (ERROR level)
- **PHPMD**: No rule against ternary operators
- **Codebase**: Uses ternary operators extensively (25+ found in SaveObject.php alone)

**Examples from codebase**:
```php
// From SaveObject.php (11 instances)
$currentPath = (($prefix !== '') === true) ? $prefix.'.'.$key : $key;
$fileConfig = $isArrayProperty === true ? ($propertyConfig['items'] ?? []) : $propertyConfig;
$object[$propertyName] = $isArrayProperty === true ? [] : null;

// From ObjectEntityMapper.php (13+ instances)
$basicRegister = isset($metadataFilters['register']) === true ? null : $register;
$deletedColumn = $tableAlias !== '' ? $tableAlias.'.deleted' : 'deleted';
$value = $value === true ? 1 : 0;
```

**PHPCS Errors**:
```
339 | ERROR | Inline IF statements are not allowed
870 | ERROR | Inline IF statements are not allowed
1168 | ERROR | Inline IF statements are not allowed
(... hundreds more)
```

**Options**:
1. **Remove PHPCS rule** (recommended) - Ternary operators are idiomatic PHP
2. **Refactor all ternary operators** - Massive code change (~200+ instances)
3. **Suppress PHPCS rule** - Use `@codingStandardsIgnoreLine` annotations

**Recommendation**: **Remove the PHPCS rule**
```xml
<!-- REMOVE from phpcs.xml line 52: -->
<rule ref="Squiz.PHP.DisallowInlineIf"/>
```

---

### 🟡 3. ElseExpression
**Status**: PHILOSOPHICAL DIFFERENCE

**Conflict**:
- **PHPCS**: No rule against else expressions
- **PHPMD** (line 14): `ElseExpression` - flags ALL else clauses (884 instances)

**Philosophy**:
- PHPMD prefers "guard clauses" (early returns)
- PHPCS allows traditional if/else

**Example**:
```php
// PHPMD wants this (guard clause):
if (!$condition) {
    return $error;
}
return $success;

// Current codebase uses this (traditional):
if ($condition) {
    return $success;
} else {
    return $error;
}
```

**Options**:
1. **Remove PHPMD rule** - Accept traditional if/else
2. **Refactor to guard clauses** - Large refactoring effort (884 instances)
3. **Keep both** - Accept that PHPMD is more strict (no actual conflict)

**Recommendation**: **Keep PHPMD rule** (it's good practice, fix incrementally)

---

### 🟡 4. ShortVariable
**Status**: STRICTNESS DIFFERENCE

**Conflict**:
- **PHPCS**: Squiz.NamingConventions.ValidVariableName - Does NOT flag short names like $id
- **PHPMD** (line 56): `ShortVariable` - Flags variables < 3 characters (496 instances)

**Common violations**:
```php
$id   // 400+ instances - very common for entity IDs
$db   // Database connection instances
$qb   // Query builder instances
$op   // Operation variable
$t    // Temporary/loop counter
```

**Options**:
1. **Remove PHPMD rule** - Accept short, idiomatic names like $id
2. **Configure PHPMD** - Set minimum length to 2 instead of 3
3. **Rename variables** - $id → $objectId, $db → $database (massive change)

**Recommendation**: **Configure PHPMD to allow 2-character variables**
```xml
<rule ref="rulesets/naming.xml/ShortVariable">
    <properties>
        <property name="minimum" value="2" />
    </properties>
</rule>
```

---

### 🟢 5. StaticAccess
**Status**: PHPMD MORE STRICT (not a real conflict)

**Info**:
- **PHPCS**: No rule against static method calls
- **PHPMD** (line 15): `StaticAccess` - Flags static method calls (64 instances)

**Examples**:
```php
parent::insert($entity);           // Necessary for inheritance
Uuid::isValid($object);            // Utility method
DateTime::createFromFormat(...);   // PHP standard library
```

**Options**:
1. **Keep PHPMD rule** - Good practice for dependency injection
2. **Suppress specific instances** - parent::, utility classes are acceptable
3. **Remove PHPMD rule** - Static access is sometimes necessary

**Recommendation**: **Keep PHPMD rule**, suppress where necessary
```php
/** @SuppressWarnings(PHPMD.StaticAccess) */
$entity = parent::insert($entity);
```

---

## Rules That Align Correctly

### ✅ Yoda Conditions
- **PHPCS**: `Generic.ControlStructures.DisallowYodaConditions` - FORBIDS
- **PHPMD**: No rule
- **Codebase**: No Yoda conditions found ✓

### ✅ Forbidden Functions
- **PHPCS**: Forbids `var_dump`, `die`, `error_log`, `print`, `is_null`, etc.
- **PHPMD**: `DevelopmentCodeFragment` catches debug code
- **Codebase**: No violations found ✓

### ✅ Superglobals
- **PHPCS**: No specific rule
- **PHPMD**: `Superglobals` flags $_GET, $_POST, etc. (14 instances)
- **Alignment**: Both tools encourage not using superglobals ✓

---

## Summary of Recommendations

### Immediate Actions (Required)

1. **✅ DONE: Remove CamelCase rules from PHPMD**
   - Eliminated 130 violations
   - Aligns with codebase convention

2. **🔴 CRITICAL: Remove DisallowInlineIf from PHPCS**
   - Codebase uses 200+ ternary operators
   - Ternary operators are idiomatic PHP
   ```xml
   <!-- Comment out or remove line 52 in phpcs.xml -->
   <!-- <rule ref="Squiz.PHP.DisallowInlineIf"/> -->
   ```

3. **🟡 RECOMMENDED: Configure ShortVariable in PHPMD**
   - Allow 2-character variables like $id, $db, $qb
   - These are idiomatic and universally understood
   ```xml
   <rule ref="rulesets/naming.xml/ShortVariable">
       <properties>
           <property name="minimum" value="2" />
       </properties>
   </rule>
   ```

### Long-term Considerations

4. **ElseExpression**: Keep PHPMD rule, refactor incrementally
   - 884 instances - too many to fix at once
   - Good practice (guard clauses improve readability)
   - Fix opportunistically when working on files

5. **StaticAccess**: Keep PHPMD rule, suppress where necessary
   - 64 instances - mostly legitimate (parent::, utility classes)
   - Good reminder to use dependency injection
   - Suppress with @SuppressWarnings where static is necessary

---

## Impact Analysis

| Conflict | Instances | Fixed | Remaining | Priority |
|----------|-----------|-------|-----------|----------|
| CamelCaseParameterName | 112 | 112 | 0 | ✅ DONE |
| CamelCaseVariableName | 16 | 16 | 0 | ✅ DONE |
| **DisallowInlineIf** | **~200** | **0** | **~200** | **🔴 CRITICAL** |
| **ShortVariable** | **496** | **0** | **~496** | **🟡 HIGH** |
| ElseExpression | 884 | 0 | 884 | 🟢 LOW (refactor over time) |
| StaticAccess | 64 | 0 | 64 | 🟢 LOW (suppress where needed) |

---

## Configuration Changes Required

### phpcs.xml
```xml
<!-- Line 52 - Comment out or remove: -->
<!-- <rule ref="Squiz.PHP.DisallowInlineIf"/> -->
```

### phpmd.xml (already updated)
```xml
<!-- Lines 39-42 - Already removed: -->
<!-- <rule ref="rulesets/controversial.xml/CamelCaseParameterName"/> -->
<!-- <rule ref="rulesets/controversial.xml/CamelCaseVariableName"/> -->

<!-- Line 56 - Add property configuration: -->
<rule ref="rulesets/naming.xml/ShortVariable">
    <properties>
        <property name="minimum" value="2" />
    </properties>
</rule>
```

---

## Expected Results After Changes

**Current state**:
- PHPMD violations: ~3,770
- PHPCS errors: Unknown (hundreds of inline if errors)
- Conflicts: 3 major conflicts

**After implementing recommendations**:
- PHPMD violations: ~3,274 (496 fewer ShortVariable violations)
- PHPCS errors: Significantly reduced (no inline if errors)
- Conflicts: 0 critical conflicts remaining

**Total violations eliminated by aligning tools**: ~826 violations

---

## Conclusion

The main conflicts between PHPMD and PHPCS stem from:
1. **✅ RESOLVED**: Different conventions on underscore-prefixed parameters (fixed)
2. **🔴 CRITICAL**: PHPCS forbids ternary operators that codebase uses extensively
3. **🟡 RECOMMENDED**: PHPMD too strict on short variable names ($id, $db, $qb)

**Immediate action required**: Remove `Squiz.PHP.DisallowInlineIf` from phpcs.xml to align with codebase practices.

**Next action**: Configure ShortVariable minimum to 2 characters to allow idiomatic names like $id.

These changes will eliminate ~826 false positive violations and align both tools with the actual coding standards used in the codebase.

