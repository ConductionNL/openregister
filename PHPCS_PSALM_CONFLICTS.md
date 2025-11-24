# PHPCS vs Psalm Conflicts

This document explains conflicts between PHPCS coding standards and Psalm static analysis, and how we resolve them.

## Conflict: Explicit Boolean Comparisons

### PHPCS Requirement
The `Squiz.Operators.ComparisonOperatorUsage` rule requires explicit boolean comparisons:
- ✅ `if (is_array($order) === true)` - Explicit comparison
- ❌ `if (is_array($order))` - Implicit truthiness check

### Psalm Analysis
Psalm flags explicit comparisons as redundant when the function already returns a boolean:
- `is_array()` returns `bool`, so `=== true` is redundant
- `empty()` returns `bool`, so `=== false` is redundant

### Resolution Strategy

1. **Keep PHPCS compliance**: Use `=== true` / `=== false` for explicit comparisons
2. **Suppress Psalm warnings**: Add `@psalm-suppress RedundantCondition` annotations specifically for PHPCS-required explicit comparisons
3. **Document the conflict**: Include a comment explaining why the suppression is needed

This approach:
- Maintains PHPCS compliance (explicit comparisons required)
- Keeps Psalm checking other redundant conditions (not suppressed globally)
- Documents the conflict clearly in code

### Examples

```php
// PHPCS requires explicit comparison, Psalm flags as redundant
/** @psalm-suppress RedundantCondition - PHPCS requires explicit comparison (Squiz.Operators.ComparisonOperatorUsage) */
if (is_array($order) === true) {
    // ...
}
```

**When to add suppressions:**
- Only for PHPCS-required explicit comparisons (`=== true` / `=== false`)
- Only when Psalm actually flags them as RedundantCondition errors
- Always include a comment explaining the PHPCS requirement

**When NOT to suppress:**
- Other types of redundant conditions (null checks, type checks, etc.)
- Conditions that are truly redundant and should be fixed

## Conflict: Null vs Empty String Checks

### The Difference
- `!== null` checks if a value is not null
- `!== ''` checks if a string is not empty

### When Both Are Needed
For nullable strings (`?string`), both checks may be needed:
```php
if ($targetUuid !== null && $targetUuid !== '') {
    // Process non-empty UUID
}
```

### When Only One Is Needed
For non-nullable strings (cast to `string`), only empty check is needed:
```php
$targetId = (string) $targetSchema->getId();
// $targetId can never be null, only empty string
if ($targetId !== '') {
    // Process non-empty ID
}
```

## Summary

- **PHPCS compliance**: Always use explicit comparisons (`=== true`, `=== false`)
- **Psalm suppressions**: Add suppressions for PHPCS-required explicit comparisons
- **Null checks**: Only check `!== null` for nullable types
- **Empty checks**: Always check `!== ''` for strings that might be empty

