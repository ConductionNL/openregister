# Handler → Service Violations (COMPLETE LIST)

## Rule: Handlers MUST NOT inject Services

## Violations Found in `/lib/Service/Object/`

### Critical Violations (Handlers injecting Services):

1. **DeleteObject.php** ❌
   - `FileService`

2. **FacetHandler.php** ❌
   - `FacetService`

3. **GetObject.php** ❌
   - `FileService`
   - `SettingsService`

4. **MergeHandler.php** ❌
   - `FileService`

5. **PerformanceHandler.php** ❌
   - `CacheHandler` (this is actually a service!)

6. **PerformanceOptimizationHandler.php** ❌
   - `OrganisationService`

7. **PermissionHandler.php** ❌
   - `OrganisationService`

8. **SaveObject.php** ❌
   - `FileService`
   - `OrganisationService`
   - `SettingsService`

9. **SaveObjects.php** ❌
   - `OrganisationService`

10. **SearchQueryHandler.php** ❌
    - `SettingsService`
    - `SearchTrailService`

11. **VectorizationHandler.php** ❌
    - `VectorizationService`

## Which Services CAN Handlers Use?

✅ **ALLOWED:**
- Mappers (ObjectEntityMapper, SchemaMapper, RegisterMapper, etc.)
- Low-level utilities (IUserSession, IGroupManager, LoggerInterface, etc.)
- Database connection (IDBConnection)

❌ **NOT ALLOWED:**
- Any class ending in "Service"
- FileService, FacetService, SettingsService, OrganisationService, etc.
- CacheHandler (it's really a service)

## Why This Matters

These service injections create CIRCULAR DEPENDENCIES:

```
ObjectService → Handler → Service → (possibly back to ObjectService)
```

## The Fix

ALL handlers must:
1. Remove service injections
2. Use ONLY mappers and low-level utilities
3. Implement their own logic or delegate to mappers

## Estimated Files to Fix: 11 handlers

