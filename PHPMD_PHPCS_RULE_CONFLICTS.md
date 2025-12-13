# PHPMD vs PHPCS Rule Configuration Analysis

## Purpose
This document analyzes whether the PHPMD rules and PHPCS rules contradict each other - i.e., if following one tool's rules would cause violations in the other tool.

## Configuration Comparison

### 1. Variable Naming Conventions

#### PHPCS Configuration (phpcs.xml lines 111-115):
```xml
<!-- Check var names, but we don't want leading underscores for private vars -->
<rule ref="Squiz.NamingConventions.ValidVariableName"/>
<rule ref="Squiz.NamingConventions.ValidVariableName.PrivateNoUnderscore">
    <severity>0</severity>
</rule>
```
**Means**: PHPCS **ALLOWS** leading underscores on variables (like `$_unusedVar`)

#### PHPMD Configuration (phpmd.xml):
```xml
<!-- Lines 39-40 REMOVED to prevent conflict: -->
<!-- <rule ref="rulesets/controversial.xml/CamelCaseParameterName"/> -->
<!-- <rule ref="rulesets/controversial.xml/CamelCaseVariableName"/> -->
```
**Means**: PHPMD now **ALLOWS** leading underscores (no rule against it)

**Conflict Status**: ✅ **RESOLVED** (by removing PHPMD rules)
- **Before**: PHPCS allowed `$_param`, PHPMD forbid it → CONFLICT
- **After**: Both allow leading underscores → NO CONFLICT

---

### 2. Private Method/Property Naming

#### PHPCS Configuration (phpcs.xml lines 181-188):
```xml
<!-- Private methods MUST not be prefixed with an underscore -->
<rule ref="PSR2.Methods.MethodDeclaration.Underscore">
    <type>error</type>
</rule>

<!-- Private properties MUST not be prefixed with an underscore -->
<rule ref="PSR2.Classes.PropertyDeclaration.Underscore">
    <type>error</type>
</rule>
```
**Means**: PHPCS **FORBIDS** underscores on private methods and properties

#### PHPMD Configuration:
```xml
<rule ref="rulesets/controversial.xml/CamelCaseMethodName"/>
<rule ref="rulesets/controversial.xml/CamelCasePropertyName"/>
```
**Means**: PHPMD also **FORBIDS** underscores on methods and properties

**Conflict Status**: ✅ **ALIGNED** (both forbid underscores on methods/properties)

---

### 3. Inline If Statements / Ternary Operators

#### PHPCS Configuration (phpcs.xml line 52):
```xml
<rule ref="Squiz.PHP.DisallowInlineIf"/>
```
**Means**: PHPCS **FORBIDS** ternary operators like `$x = $a ? $b : $c;`

#### PHPMD Configuration:
No rule about inline if/ternary operators
**Means**: PHPMD **ALLOWS** ternary operators

**Conflict Status**: ✅ **NO CONFLICT** (PHPCS is stricter, but no contradiction)
- If you write: `$x = $a ? $b : $c;`
- PHPCS: ❌ ERROR
- PHPMD: ✅ OK
- **Result**: PHPCS will catch it, no contradiction

---

### 4. Else Expressions

#### PHPCS Configuration:
No rule about else clauses
**Means**: PHPCS **ALLOWS** else expressions

#### PHPMD Configuration (phpmd.xml line 14):
```xml
<rule ref="rulesets/cleancode.xml/ElseExpression"/>
```
**Means**: PHPMD **DISCOURAGES** else (prefers guard clauses)

**Conflict Status**: ✅ **NO CONFLICT** (PHPMD is stricter, but no contradiction)
- If you write: `if ($x) { ... } else { ... }`
- PHPCS: ✅ OK
- PHPMD: ⚠️ WARNING (prefers early return)
- **Result**: PHPMD suggests improvement, no contradiction

---

### 5. Static Method Calls

#### PHPCS Configuration:
No rule forbidding static calls
**Means**: PHPCS **ALLOWS** static method calls

#### PHPMD Configuration (phpmd.xml line 15):
```xml
<rule ref="rulesets/cleancode.xml/StaticAccess"/>
```
**Means**: PHPMD **DISCOURAGES** static calls (prefers dependency injection)

**Conflict Status**: ✅ **NO CONFLICT** (PHPMD is stricter, but no contradiction)
- If you write: `SomeClass::staticMethod()`
- PHPCS: ✅ OK
- PHPMD: ⚠️ WARNING
- **Result**: PHPMD suggests improvement, no contradiction

---

### 6. Short Variable Names

#### PHPCS Configuration (phpcs.xml lines 111-115):
```xml
<rule ref="Squiz.NamingConventions.ValidVariableName"/>
<!-- Excludes PEAR.NamingConventions.ValidVariableName (line 26) -->
```
**Means**: PHPCS checks variable naming but doesn't enforce minimum length

#### PHPMD Configuration (phpmd.xml line 56):
```xml
<rule ref="rulesets/naming.xml/ShortVariable"/>
<!-- Default minimum: 3 characters -->
```
**Means**: PHPMD requires variables to be at least 3 characters

**Conflict Status**: ✅ **NO CONFLICT** (PHPMD is stricter, but no contradiction)
- If you write: `$id`
- PHPCS: ✅ OK
- PHPMD: ❌ ERROR (too short)
- **Result**: PHPMD will catch it, no contradiction

**Note**: May want to configure PHPMD to allow 2-character vars:
```xml
<rule ref="rulesets/naming.xml/ShortVariable">
    <properties>
        <property name="minimum" value="2" />
    </properties>
</rule>
```

---

### 7. Yoda Conditions

#### PHPCS Configuration (phpcs.xml line 70):
```xml
<rule ref="Generic.ControlStructures.DisallowYodaConditions"/>
```
**Means**: PHPCS **FORBIDS** Yoda conditions like `if (null === $var)`

#### PHPMD Configuration:
No rule about Yoda conditions
**Means**: PHPMD **ALLOWS** Yoda conditions

**Conflict Status**: ✅ **NO CONFLICT** (PHPCS is stricter, but no contradiction)

---

### 8. Forbidden Functions

#### PHPCS Configuration (phpcs.xml lines 165-178):
```xml
<rule ref="Generic.PHP.ForbiddenFunctions">
    <property name="forbiddenFunctions" type="array">
        <element key="var_dump" value="null"/>
        <element key="die" value="null"/>
        <element key="error_log" value="null"/>
        <element key="print" value="echo"/>
        <!-- etc. -->
    </property>
</rule>
```
**Means**: PHPCS **FORBIDS** var_dump, die, error_log, etc.

#### PHPMD Configuration (phpmd.xml line 49):
```xml
<rule ref="rulesets/design.xml/DevelopmentCodeFragment"/>
```
**Means**: PHPMD **FORBIDS** development/debug code

**Conflict Status**: ✅ **ALIGNED** (both forbid debug code)

---

### 9. Superglobals

#### PHPCS Configuration:
No specific rule about superglobals

#### PHPMD Configuration (phpmd.xml line 35):
```xml
<rule ref="rulesets/controversial.xml/Superglobals"/>
```
**Means**: PHPMD **FORBIDS** accessing $_GET, $_POST, $_SERVER directly

**Conflict Status**: ✅ **NO CONFLICT** (PHPMD is stricter, but no contradiction)

---

### 10. Boolean Arguments

#### PHPCS Configuration:
No rule about boolean function arguments

#### PHPMD Configuration (phpmd.xml line 13):
```xml
<rule ref="rulesets/cleancode.xml/BooleanArgumentFlag"/>
```
**Means**: PHPMD **DISCOURAGES** boolean parameters (suggests splitting methods)

**Conflict Status**: ✅ **NO CONFLICT** (PHPMD is stricter, but no contradiction)

---

## Summary

### Configuration Conflicts

| Rule Category | PHPCS | PHPMD | Status | Notes |
|--------------|-------|-------|--------|-------|
| **Underscore on variables** | ✅ Allow | ✅ Allow | ✅ **ALIGNED** | Fixed by removing PHPMD rules |
| **Underscore on methods/properties** | ❌ Forbid | ❌ Forbid | ✅ **ALIGNED** | Both forbid |
| **Ternary operators** | ❌ Forbid | ✅ Allow | ✅ **NO CONFLICT** | PHPCS stricter |
| **Else clauses** | ✅ Allow | ⚠️ Warn | ✅ **NO CONFLICT** | PHPMD stricter |
| **Static calls** | ✅ Allow | ⚠️ Warn | ✅ **NO CONFLICT** | PHPMD stricter |
| **Short variables** | ✅ Allow | ❌ Forbid | ✅ **NO CONFLICT** | PHPMD stricter |
| **Yoda conditions** | ❌ Forbid | ✅ Allow | ✅ **NO CONFLICT** | PHPCS stricter |
| **Debug functions** | ❌ Forbid | ❌ Forbid | ✅ **ALIGNED** | Both forbid |
| **Superglobals** | ✅ Allow | ⚠️ Warn | ✅ **NO CONFLICT** | PHPMD stricter |
| **Boolean args** | ✅ Allow | ⚠️ Warn | ✅ **NO CONFLICT** | PHPMD stricter |

### Legend
- ✅ Allow = Tool allows this pattern
- ❌ Forbid = Tool flags as error
- ⚠️ Warn = Tool suggests avoiding but not error

---

## Conclusion

### ✅ NO CONFIGURATION CONFLICTS REMAIN

After removing the `CamelCaseParameterName` and `CamelCaseVariableName` rules from PHPMD, there are **NO CONFLICTS** between the two tools.

The configurations work together harmoniously:
- Where they overlap (debug code, underscore methods), they agree
- Where PHPCS is stricter (ternary, Yoda), PHPMD doesn't contradict
- Where PHPMD is stricter (else, static, short vars), PHPCS doesn't contradict

### Configuration Philosophy

The tools complement each other:
- **PHPCS**: Focuses on code formatting, style consistency, and PSR compliance
- **PHPMD**: Focuses on code quality, complexity, and design patterns

**Result**: You can follow both tools' recommendations without contradictions. Code that passes both tools will be:
- Well-formatted and PSR-compliant (PHPCS)
- High quality with low complexity (PHPMD)

---

## Optional Adjustments

While there are no conflicts, you may want to adjust PHPMD's strictness:

### 1. Allow 2-Character Variables (Optional)
**Why**: Common variables like `$id`, `$db`, `$qb` are idiomatic
```xml
<rule ref="rulesets/naming.xml/ShortVariable">
    <properties>
        <property name="minimum" value="2" />
    </properties>
</rule>
```

### 2. Suppress ElseExpression Warnings (Optional)
**Why**: 884 warnings is a lot, may want to fix incrementally
```xml
<!-- Option: Remove if too noisy -->
<!-- <rule ref="rulesets/cleancode.xml/ElseExpression"/> -->
```

### 3. Suppress StaticAccess for Utility Classes (Optional)
**Why**: Static calls to utility classes (Uuid::isValid) are acceptable
```php
// Suppress per-line when necessary:
/** @SuppressWarnings(PHPMD.StaticAccess) */
$valid = Uuid::isValid($value);
```

---

## Verification Commands

### Check PHPCS compliance:
```bash
./vendor/bin/phpcs lib
```

### Check PHPMD compliance:
```bash
./vendor/bin/phpmd lib text phpmd.xml
```

### Both should now work together without conflicts!

---

**Last Updated**: December 13, 2025
**Configuration Status**: ✅ NO CONFLICTS

