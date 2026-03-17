# Vectorization Architecture Refactoring

**Date:** 2024-12-15  
**Status:** ✅ Completed

## Summary

The vectorization infrastructure has been refactored to consolidate all vector-related operations under a single namespace with a clear public API facade pattern.

## Changes

### 1. Namespace Consolidation

The vectorization services are organized as follows:

```
lib/Service/
├── VectorizationService.php          (Public API - Facade)
└── Vectorization/
    ├── VectorEmbeddingService.php    (Internal - Handler)
    └── Strategies/
        ├── VectorizationStrategyInterface.php
        ├── ObjectVectorizationStrategy.php
        └── FileVectorizationStrategy.php
```

**Before:**
```php
use OCA\OpenRegister\Service\VectorEmbeddingService;
use OCA\OpenRegister\Service\VectorizationService;
use OCA\OpenRegister\Service\Vectorization\ObjectVectorizationStrategy;
```

**After:**
```php
use OCA\OpenRegister\Service\VectorizationService;
use OCA\OpenRegister\Service\Vectorization\VectorEmbeddingService;
use OCA\OpenRegister\Service\Vectorization\Strategies\ObjectVectorizationStrategy;
```

### 2. Public API Facade

**VectorizationService** is now the single entry point for all vector operations:

- Other services should call `VectorizationService` methods
- `VectorEmbeddingService` is an internal implementation detail
- All public methods from `VectorEmbeddingService` are exposed via `VectorizationService`

**Example:**

```php
// Before (❌ DON'T)
$vectorEmbeddingService->semanticSearch($query, $limit);

// After (✅ DO)
$vectorizationService->semanticSearch($query, $limit);
```

### 3. Facade Methods

VectorizationService exposes these public methods:

- `vectorizeBatch()` - Batch vectorization of entities
- `registerStrategy()` - Register entity strategies
- `generateEmbedding()` - Generate single embedding
- `semanticSearch()` - Perform semantic search
- `hybridSearch()` - Perform hybrid search (SOLR + vectors)
- `getVectorStats()` - Get vector statistics
- `testEmbedding()` - Test embedding configuration
- `checkEmbeddingModelMismatch()` - Check model consistency
- `clearAllEmbeddings()` - Clear all embeddings

## Benefits

### 1. Single Entry Point
All vector operations go through one service - clear API boundary.

### 2. Encapsulation
VectorEmbeddingService is private implementation - can be swapped/refactored without affecting consumers.

### 3. Better Organization
All vectorization code in one namespace, easier to navigate.

### 4. Future-Proof
Easy to add new embedding providers or change storage backend without breaking API.

## Migration Guide

### For Service Developers

If your service uses `VectorEmbeddingService`, update it:

1. **Update imports:**
```php
// Old
use OCA\OpenRegister\Service\VectorEmbeddingService;

// New
use OCA\OpenRegister\Service\VectorizationService;
```

2. **Update constructor:**
```php
// Old
public function __construct(
    private VectorEmbeddingService $vectorService
) {}

// New
public function __construct(
    private VectorizationService $vectorizationService
) {}
```

3. **Update method calls:**
All methods remain the same, just use the new service instance.

### For Controller Developers

Same as above - update imports and use `VectorizationService` instead of `VectorEmbeddingService`.

### For Test Developers

Update test files to use new namespaces:

```php
use OCA\OpenRegister\Service\VectorizationService;
use OCA\OpenRegister\Service\Vectorization\VectorEmbeddingService;
```

## Architecture Diagram

```
┌─────────────────────────────────────────────────────┐
│ Controllers / Services                               │
│ (ChatService, SettingsController, etc.)             │
└─────────────────┬───────────────────────────────────┘
                  │
                  │ Call public API
                  ▼
┌─────────────────────────────────────────────────────┐
│ VectorizationService (Public Facade)                │
│ - vectorizeBatch()                                   │
│ - semanticSearch()                                   │
│ - hybridSearch()                                     │
│ - getVectorStats()                                   │
│ - testEmbedding()                                    │
└─────────────────┬───────────────────────────────────┘
                  │
                  │ Delegates to
                  ▼
┌─────────────────────────────────────────────────────┐
│ VectorEmbeddingService (Internal Handler)           │
│ - generateEmbedding()                                │
│ - storeVector()                                      │
│ - semanticSearch()                                   │
│ - LLM provider management                            │
└─────────────────┬───────────────────────────────────┘
                  │
                  │ Uses
                  ▼
┌─────────────────────────────────────────────────────┐
│ Strategies (Entity-specific logic)                  │
│ - ObjectVectorizationStrategy                        │
│ - FileVectorizationStrategy                          │
└─────────────────────────────────────────────────────┘
```

## Files Changed

### Services
- `lib/Service/Vectorization/VectorizationService.php` - Moved and updated
- `lib/Service/Vectorization/VectorEmbeddingService.php` - Moved
- `lib/Service/Vectorization/Strategies/*.php` - Moved

### Controllers
- `lib/Controller/SettingsController.php` - Updated to use VectorizationService
- `lib/Controller/Settings/LlmSettingsController.php` - Updated
- `lib/Controller/Settings/VectorSettingsController.php` - Updated
- `lib/Controller/SolrController.php` - Updated
- `lib/Controller/FileSearchController.php` - Updated
- `lib/Controller/FileExtractionController.php` - Updated

### Other Services
- `lib/Service/ChatService.php` - Updated to use VectorizationService
- `lib/AppInfo/Application.php` - Updated dependency injection

## Testing

All existing tests should continue to work with updated imports. No behavioral changes were made.

To verify:

```bash
composer test:unit
composer phpqa
```

## Related Documentation

- [Vectorization Architecture](./vectorization-architecture.md)
- [Vectorization Technical Guide](./vectorization.md)
- [Services Architecture](../development/services-architecture.md)

