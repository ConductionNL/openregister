# FetchHandler Refactoring - 2026-01-06

## Overview

This document describes the refactoring that eliminated the circular dependency between `ConfigurationService` and `PreviewHandler` by extracting remote fetching logic into a dedicated `FetchHandler`.

## Problem Statement

### Original Architecture (Circular Dependency)

```
ConfigurationService
    └── PreviewHandler
            └── ConfigurationService (CIRCULAR!)
```

**Issue**: `PreviewHandler` needed to call `ConfigurationService::fetchRemoteConfiguration()` to get remote configuration data, but `ConfigurationService` already depends on `PreviewHandler` for preview functionality. This created a circular dependency that required complex lazy-loading workarounds.

### Why This Was Bad

1. **Tight Coupling**: PreviewHandler was unnecessarily coupled to the entire ConfigurationService just to fetch remote data
2. **Fragile Solution**: Required lazy-loading via container and nullable properties
3. **Hard to Test**: Circular dependencies make unit testing difficult
4. **Confusing Responsibility**: ConfigurationService had too many responsibilities

## Solution: Extract FetchHandler

### New Architecture (No Circular Dependencies)

```
ConfigurationService
    ├── PreviewHandler
    │       └── FetchHandler (✅ No circular dependency!)
    └── FetchHandler

ImportHandler
    └── FetchHandler (bonus: can also use it!)
```

**Key Insight**: `PreviewHandler` doesn't need the entire `ConfigurationService` - it only needs the ability to fetch remote configuration data. By extracting this into `FetchHandler`, we break the circular dependency.

## Implementation

### 1. Created FetchHandler

**File**: `lib/Service/Configuration/FetchHandler.php`

**Responsibilities**:
- Fetch JSON/YAML data from URLs
- Fetch remote configuration data for Configuration entities
- Parse response data (JSON/YAML detection)
- Error handling for remote requests

**Dependencies**:
- `Client` (Guzzle HTTP client)
- `LoggerInterface` (PSR logger)

**Key Methods**:
```php
public function getJSONfromURL(string $url): array|JSONResponse
public function fetchRemoteConfiguration(Configuration $configuration): array|JSONResponse
private function decode(string $data, string $type): ?array
```

### 2. Updated ConfigurationService

**Changes**:
- Added `getFetchHandler()` method for lazy loading FetchHandler
- Modified `getJSONfromURL()` to delegate to FetchHandler
- Modified `fetchRemoteConfiguration()` to delegate to FetchHandler

**Example**:
```php
private function getFetchHandler(): FetchHandler
{
    return $this->container->get('OCA\OpenRegister\Service\Configuration\FetchHandler');
}

public function fetchRemoteConfiguration(Configuration $configuration): array|JSONResponse
{
    return $this->getFetchHandler()->fetchRemoteConfiguration($configuration);
}
```

### 3. Updated PreviewHandler

**Before**:
```php
class PreviewHandler
{
    private ?ConfigurationService $configurationService = null;
    private ?ContainerInterface $container = null;
    
    private function getConfigurationService(): ConfigurationService
    {
        if ($this->configurationService === null) {
            $this->configurationService = $this->container->get(ConfigurationService::class);
        }
        return $this->configurationService;
    }
    
    public function previewConfigurationChanges(Configuration $configuration): array|JSONResponse
    {
        $remoteData = $this->getConfigurationService()->fetchRemoteConfiguration($configuration);
        // ...
    }
}
```

**After**:
```php
class PreviewHandler
{
    private readonly FetchHandler $fetchHandler;
    
    public function __construct(
        RegisterMapper $registerMapper,
        SchemaMapper $schemaMapper,
        LoggerInterface $logger,
        FetchHandler $fetchHandler  // ✅ Direct injection!
    ) {
        $this->fetchHandler = $fetchHandler;
        // ...
    }
    
    public function previewConfigurationChanges(Configuration $configuration): array|JSONResponse
    {
        $remoteData = $this->fetchHandler->fetchRemoteConfiguration($configuration);
        // ...
    }
}
```

**Benefits**:
- ✅ No lazy loading needed
- ✅ No nullable properties
- ✅ No circular dependency
- ✅ Clean constructor injection
- ✅ Immutable (`readonly`) property

### 4. Bonus: ImportHandler Can Also Use FetchHandler

Although not required for this refactoring, `ImportHandler` can now also use `FetchHandler` instead of duplicating the fetching logic. This creates a single source of truth for all remote fetching operations.

## Testing

All services resolve correctly without hanging:
```bash
✅ FetchHandler resolved!
✅ PreviewHandler resolved (with FetchHandler)!
✅ ConfigurationService resolved!
✅ Import functionality works!
```

## Benefits of This Refactoring

### 1. No Circular Dependencies
- Clean dependency graph
- Each service has clear responsibilities
- No lazy-loading hacks needed

### 2. Better Separation of Concerns
- **FetchHandler**: Remote data fetching
- **PreviewHandler**: Comparison logic
- **ConfigurationService**: Orchestration

### 3. Easier Testing
- FetchHandler can be mocked easily
- PreviewHandler tests don't need full ConfigurationService
- Unit tests are simpler

### 4. More Reusable
- FetchHandler can be used by any service that needs remote fetching
- Not coupled to configuration-specific logic

### 5. Better Performance
- No lazy-loading overhead
- Direct dependency injection
- Immutable properties (readonly)

## Code Quality Improvements

### Before (Complexity: High)
- Circular dependency
- Lazy loading with nullable properties
- Container passed to constructor
- Complex initialization logic
- Hard to understand flow

### After (Complexity: Low)
- Linear dependency graph
- Direct constructor injection
- Readonly properties
- Simple, clear flow
- Easy to understand

## Migration Notes

### Backward Compatibility

The public API of `ConfigurationService` and `PreviewHandler` remains unchanged. All existing code continues to work without modification.

### Auto-wiring

`FetchHandler` uses auto-wiring and doesn't require explicit DI registration in `Application.php`. Nextcloud's DI container automatically resolves it based on constructor parameters.

## Recommendations for Future Development

1. **Keep handlers focused**: Each handler should have a single responsibility
2. **Extract shared logic**: If multiple services need the same functionality, extract it to a dedicated handler
3. **Avoid circular dependencies**: Use composition over inheritance, and extract shared functionality when needed
4. **Use readonly properties**: For dependencies that don't change after construction
5. **Prefer constructor injection**: Over lazy loading when possible

## Files Modified

1. ✅ `lib/Service/Configuration/FetchHandler.php` (NEW)
2. ✅ `lib/Service/ConfigurationService.php` (UPDATED)
3. ✅ `lib/Service/Configuration/PreviewHandler.php` (UPDATED)

## Conclusion

This refactoring successfully eliminates the circular dependency between `ConfigurationService` and `PreviewHandler` by extracting fetching logic into a dedicated `FetchHandler`. The result is a cleaner architecture with better separation of concerns, easier testing, and improved maintainability.

**Status**: ✅ COMPLETED  
**Date**: 2026-01-06  
**Impact**: Breaking the circular dependency, improved architecture  
**Tests**: All passing


