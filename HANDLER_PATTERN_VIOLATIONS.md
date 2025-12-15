# Handler Pattern Violations

## The Correct Pattern

✅ **Services depend on Handlers**
❌ **Handlers depend on Services**

## Violations Found

### 1. ExportHandler
**Injects:**
- `ExportService` ❌ REMOVE
- `ImportService` ❌ REMOVE  
- `FileService` ❌ REMOVE

**Should inject:**
- `ObjectEntityMapper` ✅
- `SchemaMapper` ✅
- Mappers and utilities only!

### 2. MergeHandler  
**Injects:**
- `FileService` ❌ REMOVE

**Should inject:**
- `ObjectEntityMapper` ✅
- Mappers only!

### 3. VectorizationHandler
**Injects:**
- `VectorizationService` ❌ REMOVE

**Should inject:**
- `ObjectEntityMapper` ✅
- Mappers only!

## The Fix

Handlers should:
1. ✅ Inject ONLY mappers and low-level utilities
2. ✅ Contain their OWN business logic
3. ❌ NOT call any *Service classes
4. ❌ NOT delegate back to services

## Why This Matters

```
WRONG (Circular):
ObjectService → ExportHandler → ExportService → ObjectService ♾️

CORRECT:
ObjectService → ExportHandler
ExportHandler uses mappers directly
```

## Implementation

Remove ALL service injections from handlers.
Handlers must be self-contained with mapper access only.

