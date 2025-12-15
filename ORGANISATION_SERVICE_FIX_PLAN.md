# 🔧 OrganisationService Mapper Fix Plan

## Problem
**9 mappers inject `OrganisationService`** to get the active organization, violating architecture:
❌ Mapper → Service (WRONG)

## Correct Architecture
✅ Service → Mapper (with organization passed as parameter)

## Pattern to Follow

### ❌ WRONG (Current):
```php
class SomeMapper {
    private OrganisationService $organisationService;
    
    public function findAll() {
        $org = $this->organisationService->getActiveOrganisation();
        // filter by $org
    }
}
```

### ✅ CORRECT (Target):
```php
class SomeMapper {
    // NO OrganisationService injection!
    
    public function findAll(?string $organisationUuid = null) {
        // filter by $organisationUuid parameter
    }
}

class SomeService {
    public function __construct(
        private SomeMapper $mapper,
        private OrganisationService $orgService
    ) {}
    
    public function getAll() {
        $org = $this->orgService->getActiveOrganisation();
        return $this->mapper->findAll($org?->getUuid());
    }
}
```

## Implementation Steps

### Step 1: Update MultiTenancyTrait (if used)
The trait might already handle organization filtering. Check if mappers use it.

### Step 2: Fix Each Mapper
For each of the 9 mappers:
1. Remove `OrganisationService` from constructor
2. Remove `private OrganisationService $organisationService` property
3. Add `?string $organisationUuid = null` parameter to query methods
4. Use the parameter for filtering instead of calling the service

### Step 3: Update Application.php
Remove `OrganisationService` from mapper registrations

### Step 4: Update Service Layer
Services should:
1. Call `OrganisationService->getActiveOrganisation()`
2. Pass the UUID to mapper methods

## Mappers to Fix
1. ✅ ObjectEntityMapper - Already has OrganisationService (will fix with others)
2. AgentMapper
3. ApplicationMapper
4. ConfigurationMapper
5. EndpointMapper
6. RegisterMapper
7. SchemaMapper (also has PropertyValidatorHandler)
8. SourceMapper
9. ViewMapper (also has CacheHandler)
10. WebhookMapper

## Benefits
- ✅ No circular dependencies
- ✅ Mappers are pure database access
- ✅ Better testability (can pass any org UUID)
- ✅ Follows clean architecture
- ✅ Services control business logic

## Migration Strategy
This is a large refactor (affects ~9 mappers + their services). Options:
1. **Aggressive**: Fix all at once (might break app temporarily)
2. **Conservative**: Fix one mapper at a time, test each
3. **Pragmatic**: Keep for now, document as technical debt, fix when needed

**Recommendation**: Document as technical debt, fix when each mapper/service is refactored for other reasons. The critical issue (ObjectEntityMapper handlers) is already fixed.

