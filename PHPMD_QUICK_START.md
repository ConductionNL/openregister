# PHPMD Quick Start Guide for OpenRegister

## Running PHPMD

### Basic Run
```bash
cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister
./vendor/bin/phpmd lib text phpmd.xml
```

### Save Report to File
```bash
./vendor/bin/phpmd lib text phpmd.xml > phpmd-report.txt
```

### Check Specific File
```bash
./vendor/bin/phpmd lib/Service/MyService.php text phpmd.xml
```

### JSON Output (for tools)
```bash
./vendor/bin/phpmd lib json phpmd.xml
```

## Quick Fixes

### 1. Fix MissingImport (477 violations)
```bash
# Install PHP CS Fixer
composer require --dev friendsofphp/php-cs-fixer

# Auto-fix imports
./vendor/bin/php-cs-fixer fix lib --rules=ordered_imports
```

### 2. Fix Naming Issues (128 violations)
```bash
# Use IDE refactoring or search-replace
# CamelCaseParameterName: $_myParam → $myParam
# CamelCaseVariableName: $_myVar → $myVar
```

### 3. Fix CountInLoopExpression (10 violations)
```php
// Before (BAD)
for ($i = 0; $i < count($array); $i++) {
    // ...
}

// After (GOOD)
$arrayCount = count($array);
for ($i = 0; $i < $arrayCount; $i++) {
    // ...
}
```

### 4. Fix UndefinedVariable (42 violations)
```php
// Before (BAD)
if ($condition) {
    $result = doSomething();
}
echo $result; // Undefined if $condition is false

// After (GOOD)
$result = null;
if ($condition) {
    $result = doSomething();
}
echo $result;
```

### 5. Fix ElseExpression (841 violations)
```php
// Before (BAD)
if ($isValid) {
    return $data;
} else {
    return ['error' => 'Invalid'];
}

// After (GOOD) - Early return
if (!$isValid) {
    return ['error' => 'Invalid'];
}
return $data;
```

### 6. Fix UnusedLocalVariable (55 violations)
```php
// Before (BAD)
$unusedVar = calculateSomething();
return $otherData;

// After (GOOD) - Remove unused variable
return $otherData;
```

### 7. Fix EmptyCatchBlock (4 violations)
```php
// Before (BAD)
try {
    riskyOperation();
} catch (Exception $e) {
    // Empty catch block
}

// After (GOOD)
try {
    riskyOperation();
} catch (Exception $e) {
    $this->logger->error('Operation failed', ['exception' => $e->getMessage()]);
}
```

## Common Violations and Fixes

| Violation | Quick Fix |
|-----------|-----------|
| **MissingImport** | Add `use` statement at top of file |
| **ShortVariable** | Rename `$id` → `$objectId`, `$db` → `$database` |
| **UnusedFormalParameter** | Remove parameter or prefix with `_` |
| **CamelCaseParameterName** | Rename `$_param` → `$param` |
| **CountInLoopExpression** | Extract count before loop |
| **ErrorControlOperator** | Remove `@` and add proper error handling |
| **EmptyCatchBlock** | Add logging or error handling |
| **StaticAccess** | Use dependency injection instead |
| **Superglobals** | Use Request object instead of `$_GET`, `$_POST` |

## Suppressing Violations

### For Entire File
```php
<?php
/**
 * @SuppressWarnings(PHPMD)
 */
class MyClass {
    // ...
}
```

### For Specific Rule
```php
/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
public function myMethod($id) {
    // $id is acceptable here
}
```

### Multiple Rules
```php
/**
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 */
public function complexMethod() {
    // Complex logic that can't be easily refactored
}
```

## Checking Your Work

### Before Commit
```bash
# Run PHPMD on files you changed
git diff --name-only | grep '\.php$' | xargs -I {} ./vendor/bin/phpmd {} text phpmd.xml
```

### Count Violations
```bash
./vendor/bin/phpmd lib text phpmd.xml | wc -l
```

### Check Specific Rule
```bash
./vendor/bin/phpmd lib text phpmd.xml | grep "ElseExpression"
```

## IDE Integration

### PHPStorm
1. Settings → PHP → Quality Tools → Mess Detector
2. Configuration: `/path/to/vendor/bin/phpmd`
3. Ruleset: `/path/to/phpmd.xml`
4. Enable inspection in Editor → Inspections → PHP → Mess Detector

### VSCode
1. Install extension: `phpmd`
2. Add to settings.json:
```json
{
  "phpmd.command": "./vendor/bin/phpmd",
  "phpmd.rules": "./phpmd.xml"
}
```

## CI/CD Integration

### Add to GitHub Actions
```yaml
name: Code Quality
on: [push, pull_request]
jobs:
  phpmd:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Install dependencies
        run: composer install
      - name: Run PHPMD
        run: ./vendor/bin/phpmd lib text phpmd.xml
```

## Priority Files to Fix

Focus on these files first (highest violation count):

1. lib/Service/GuzzleSolrService.php (409 violations)
2. lib/Db/ObjectEntityMapper.php (214 violations)
3. lib/Service/ObjectService.php (194 violations)
4. lib/Service/ObjectHandlers/SaveObject.php (116 violations)
5. lib/Service/ObjectHandlers/SaveObjects.php (100 violations)

## Getting Help

- **PHPMD Documentation**: https://phpmd.org/
- **Rules Reference**: https://phpmd.org/rules/index.html
- **OpenRegister Docs**: See `PHPMD_ANALYSIS.md` for detailed analysis
- **Fix Progress**: See `PHPMD_FIX_PROGRESS.md` for tracking

## Quick Stats

```bash
# Total violations
./vendor/bin/phpmd lib text phpmd.xml 2>&1 | wc -l

# By type
./vendor/bin/phpmd lib text phpmd.xml 2>&1 | awk '{print $2}' | sort | uniq -c | sort -rn

# By file
./vendor/bin/phpmd lib text phpmd.xml 2>&1 | cut -d: -f1 | sort | uniq -c | sort -rn | head -20
```

## Don't Forget

- ✅ Fix critical bugs (UndefinedVariable) first
- ✅ Run PHPMD before committing
- ✅ Use automated tools for easy fixes
- ✅ Add comments/suppressions for false positives
- ✅ Refactor incrementally, don't try to fix everything at once

---

**Quick Reference Generated**: December 13, 2025

