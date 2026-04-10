# Circular Dependency Fix - 2026-01-06

## Problem Summary
After adding new code today (UserController, MappingController, etc.), the entire import system stopped working. Any attempt to import configurations would hang indefinitely, appearing to be a circular dependency issue.

## Root Cause
**The actual problem was a PHP syntax error**, not a circular dependency!

### The Syntax Error
In 'ImportHandler.php' line 1999, we had:
```php
$schema = $this->schemaMapper->find($schemaSlug, $_multitenancy: false);
```

This caused a parse error because we were using named parameters but skipping intermediate optional parameters without naming them.

### Correct Syntax
```php
$schema = $this->schemaMapper->find(
    id: $schemaSlug,
    _extend: [],
    published: null,
    _rbac: false,
    _multitenancy: false
);
```

## Why It Was Hard to Find

1. **No Visible Error**: PHP's parser hung when trying to load the class
2. **Appeared as Circular Dependency**: The resolution process would freeze, making it seem like a dependency loop
3. **DI Container Behavior**: Nextcloud's DI container would hang without throwing an exception
4. **No Logs**: Standard logging didn't capture parse errors during class loading

## Discovery Process

We systematically tested each component:
1. ✅ ConfigurationService - worked after lazy loading fix
2. ✅ All Mappers individually - all resolved fine
3. ✅ ObjectService - resolved fine
4. ✅ All ImportHandler dependencies - resolved fine
5. ❌ ImportHandler construction - hung!

The breakthrough came when we added granular logging and discovered the factory was being called but hanging at 'new ConfigurationImportHandler(...)'.

Finally running 'php -l ImportHandler.php' revealed the parse error!

## Additional Fixes Applied

While debugging, we also fixed two real circular dependencies:

### Fix 1: ConfigurationService Lazy Loading
**Problem**: ConfigurationService injected ImportHandler in constructor, creating:
- ConfigurationService → ImportHandler → ... → ConfigurationService

**Solution**: Lazy load ImportHandler via 'getImportHandler()' method.

**Files Changed**:
- 'lib/Service/ConfigurationService.php':
  - Removed ImportHandler from constructor
  - Added 'getImportHandler()' method
  - Updated all uses of '$this->importHandler' to '$this->getImportHandler()'

- 'lib/AppInfo/Application.php':
  - Removed ImportHandler injection from ConfigurationService registration

### Fix 2: OpenConnector Auto-Resolution
**Problem**: ConfigurationService constructor called 'getOpenConnector()' which resolved OpenConnector's ConfigurationService, creating potential circular dependency.

**Solution**: Removed OpenConnector resolution from constructor.

**Files Changed**:
- 'lib/Service/ConfigurationService.php':
  - Commented out OpenConnector wiring in constructor

### Fix 3: Dual Registration for ImportHandler
**Problem**: ImportHandler was imported with alias in Application.php but referenced by full class name elsewhere, potentially causing auto-wiring conflicts.

**Solution**: Register service under both names pointing to same factory.

**Files Changed**:
- 'lib/AppInfo/Application.php':
  - Register ImportHandler under both 'ConfigurationImportHandler::class' and full class name
  - Use shared factory closure for both registrations

## Testing

After all fixes:
```bash
# Test ImportHandler resolution
php -r '
require_once "/var/www/html/lib/base.php";
\OC_App::loadApps();
$handler = \OC::$server->get("OCA\OpenRegister\Service\Configuration\ImportHandler");
echo "✅ ImportHandler resolved!\n";
'

# Test ConfigurationService
php -r '
require_once "/var/www/html/lib/base.php";
\OC_App::loadApps();
$service = \OC::$server->get("OCA\OpenRegister\Service\ConfigurationService");
echo "✅ ConfigurationService resolved!\n";
'

# Test import
php -r '
require_once "/var/www/html/lib/base.php";
\OC_App::loadApps();
$service = \OC::$server->get("OCA\OpenRegister\Service\ConfigurationService");
$result = $service->importFromApp("softwarecatalog", force: true);
echo "✅ Import completed!\n";
'
```

## Lessons Learned

1. **Always check syntax first**: 'php -l filename.php' should be first debug step
2. **Parse errors can masquerade as deadlocks**: Hanging during class loading often indicates parse error
3. **Named parameters require all intermediate params**: When skipping optional parameters, all must be named
4. **DI resolution hanging ≠ circular dependency**: Could be syntax error, autoloader issue, or actual circular dependency

## Prevention

1. Run 'php -l' on all modified files before committing
2. Use IDE syntax checking
3. Run PHPCS/PHPMD which would catch this
4. Add pre-commit hook to check PHP syntax

## Files Modified

### Core Fixes
- 'lib/Service/Configuration/ImportHandler.php'
  - Fixed named parameter syntax on line 1999

### Circular Dependency Prevention
- 'lib/Service/ConfigurationService.php'
  - Lazy loading of ImportHandler
  - Removed OpenConnector auto-resolution

- 'lib/AppInfo/Application.php'
  - Dual registration for ImportHandler
  - Removed ImportHandler from ConfigurationService DI

### Configuration
- 'docker-compose.yml'
  - Removed opencatalogi mount (unrelated cleanup)

## Status
✅ **RESOLVED** - All imports now work correctly!

