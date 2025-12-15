# Psalm Quick Reference Card

## Running Psalm

```bash
# Basic run
composer psalm

# Run on specific file
./vendor/bin/psalm --file=lib/Service/ObjectService.php

# Clear cache and run
./vendor/bin/psalm --no-cache

# Auto-fix issues
./vendor/bin/psalm --alter --issues=InvalidReturnType,MismatchingDocblockReturnType

# Regenerate baseline
./vendor/bin/psalm --set-baseline=psalm-baseline.xml

# Output to JSON
composer psalm:output
```

## Common Commands

```bash
# Count errors
composer psalm 2>&1 | grep "errors found"

# Find specific issue type
./vendor/bin/psalm --file=lib/Service/ObjectService.php | grep "UndefinedMethod"

# Run full quality check (includes Psalm)
composer phpqa

# Run with PHPStan for comparison  
composer phpstan
```

## Quick Fixes

### Fix 1: Remove invalid 'rbac' parameter
```bash
# Find all occurrences
grep -rn "rbac:" lib/

# Example fix: Remove from method calls
- $mapper->find(id: $id, rbac: false)
+ $mapper->find(id: $id)
```

### Fix 2: Remove invalid 'multi' parameter  
```bash
# Find all occurrences
grep -rn "multi:" lib/

# Example fix
- $service->get(id: $id, multi: false)
+ $service->get(id: $id)
```

### Fix 3: Remove unnecessary null coalescing
```bash
# Find patterns
grep -rn "?? \[\]" lib/Service/Object/SaveObjects.php

# Example fix
- foreach ($objects ?? [] as $obj)
+ foreach ($objects as $obj)
```

### Fix 4: Add missing property
```php
// Before
class MyService {
    public function __construct() {}
    
    public function doSomething() {
        $this->logger->info('test');  // Error!
    }
}

// After
class MyService {
    public function __construct(
        private LoggerInterface $logger
    ) {}
    
    public function doSomething() {
        $this->logger->info('test');  // Works!
    }
}
```

## Error Code Reference

| Code | Name | Priority | Quick Fix |
|------|------|----------|-----------|
| 022 | UndefinedMethod | HIGH | Check method name/implement it |
| 238 | InvalidNamedArgument | HIGH | Remove/rename parameter |
| 024 | UndefinedVariable | HIGH | Define variable |
| 041 | UndefinedThisPropertyFetch | HIGH | Add property declaration |
| 004 | InvalidArgument | HIGH | Fix type/cast properly |
| 090 | TypeDoesNotContainNull | MED | Remove ?? operator |
| 056 | TypeDoesNotContainType | MED | Fix type check |
| 179 | NoValue | MED | Remove dead code |

Full reference: https://psalm.dev/articles

## Testing After Fixes

```bash
# 1. Psalm
composer psalm

# 2. PHPCS
composer cs:check

# 3. Unit tests
composer test:unit

# 4. Full QA
composer phpqa

# 5. Docker tests
composer test:docker
```

## Git Workflow

```bash
# Create branch
git checkout -b fix/psalm-undefined-methods

# Make changes, then:
composer psalm
composer test:unit

# Commit with good message
git add .
git commit -m "fix: Remove invalid named arguments from mapper calls

- Removed 'rbac' parameter (removed in refactoring)
- Removed 'multi' parameter (removed in refactoring)
- Updated all call sites in ObjectService

Fixes: 40 InvalidNamedArgument Psalm errors"

# Push
git push origin fix/psalm-undefined-methods
```

## Psalm Configuration Files

```bash
# Main config
cat psalm.xml

# Baseline (known issues)
cat psalm-baseline.xml

# Composer scripts
cat composer.json | grep -A 5 "psalm"
```

## Common Patterns

### Pattern: Method renamed/removed
```php
// Error: UndefinedMethod
$this->objectMapper->find($id);

// Solution: Check actual method name
$this->objectMapper->findByUuid($id);
```

### Pattern: Parameter removed
```php
// Error: InvalidNamedArgument
$this->mapper->find(id: $id, rbac: false);

// Solution: Remove parameter
$this->mapper->find(id: $id);
```

### Pattern: Over-defensive code
```php
// Error: TypeDoesNotContainNull
public function process(array $items): void {
    foreach ($items ?? [] as $item) {  // $items is never null!
        // ...
    }
}

// Solution: Trust your types
public function process(array $items): void {
    foreach ($items as $item) {
        // ...
    }
}
```

### Pattern: Missing injection
```php
// Error: UndefinedThisPropertyFetch
class Service {
    public function log() {
        $this->logger->info('test');  // $logger not defined!
    }
}

// Solution: Inject via constructor
class Service {
    public function __construct(
        private LoggerInterface $logger
    ) {}
    
    public function log() {
        $this->logger->info('test');
    }
}
```

## Useful Searches

```bash
# Find all TODOs related to Psalm
grep -rn "@psalm-suppress" lib/

# Find all methods in a mapper
grep -n "public function" lib/Db/ObjectEntityMapper.php

# Find all constructor injections
grep -rn "public function __construct" lib/Service/

# Find all method calls to a specific method
grep -rn "->find(" lib/
```

## Psalm Levels

| Level | Strictness | Typical Errors |
|-------|------------|----------------|
| 1 | Maximum | <10 in excellent code |
| 2 | Very High | <50 in good code |
| 3 | High | <100 in maintained code |
| **4** | **Moderate** | **100-1000 (current)** |
| 5 | Relaxed | 500-2000 |
| 6 | Minimal | 1000+ |
| 7 | Very Minimal | 2000+ |
| 8 | Off | ∞ |

**Current:** Level 4 (moderate)  
**Goal:** Level 3 (high) then Level 2 (very high)

## Priority Matrix

| Error Type | Impact | Frequency | Priority |
|------------|--------|-----------|----------|
| UndefinedMethod | High | 80 | 🔴 Critical |
| InvalidNamedArgument | High | 60 | 🔴 Critical |
| UndefinedVariable | High | 30 | 🔴 Critical |
| UndefinedThisPropertyFetch | High | 15 | 🔴 Critical |
| TypeDoesNotContainNull | Low | 150 | 🟡 Medium |
| NoValue | Medium | 40 | 🟡 Medium |
| TypeDoesNotContainType | Low | 30 | 🟡 Medium |

## Progress Tracking

```bash
# Create script to track progress
cat > track-psalm-progress.sh << 'EOF'
#!/bin/bash
echo "=== Psalm Progress Report ==="
echo "Date: $(date)"
echo ""

# Run Psalm and capture output
OUTPUT=$(composer psalm 2>&1)

# Extract metrics
ERRORS=$(echo "$OUTPUT" | grep "errors found" | awk '{print $1}')
INFO=$(echo "$OUTPUT" | grep "other issues" | awk '{print $1}')
COVERAGE=$(echo "$OUTPUT" | grep "infer types for" | awk '{print $7}')

echo "Errors: $ERRORS (target: 0)"
echo "Info issues: $INFO"
echo "Type coverage: $COVERAGE"
echo ""

# Save to log
echo "$(date),$ERRORS,$INFO,$COVERAGE" >> psalm-progress.csv
echo "Progress saved to psalm-progress.csv"
EOF

chmod +x track-psalm-progress.sh
```

## Help Resources

- **Psalm Docs:** https://psalm.dev/docs
- **Error Reference:** https://psalm.dev/articles  
- **Playground:** https://psalm.dev/r (test code snippets)
- **GitHub:** https://github.com/vimeo/psalm/issues
- **Detailed Analysis:** See PSALM_ANALYSIS.md
- **Fix Guide:** See PSALM_FIX_GUIDE.md

## One-Liners

```bash
# Count each error type
composer psalm 2>&1 | grep "ERROR:" | awk -F: '{print $2}' | sort | uniq -c | sort -rn

# Find files with most errors
composer psalm 2>&1 | grep "ERROR:" | awk -F: '{print $3}' | sort | uniq -c | sort -rn | head -10

# Run Psalm and highlight specific error
composer psalm 2>&1 | grep --color=always "UndefinedMethod"

# Compare before/after error count
echo "Before: $(git show HEAD:psalm-baseline.xml | grep '<file' | wc -l)"
echo "After: $(cat psalm-baseline.xml | grep '<file' | wc -l)"
```

---

**Last Updated:** December 15, 2025  
**Psalm Version:** 5.26.1  
**Error Level:** 4

