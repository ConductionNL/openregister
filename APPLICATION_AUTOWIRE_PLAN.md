# Application.php Autowiring Plan

## Goal
Reduce Application.php register() method from 654 lines to <200 lines by removing unnecessary manual service registrations.

## Strategy
**Remove manual registration for services that can be autowired** (all constructor params are type-hinted interfaces/classes with no string/scalar params).

## Analysis

### ✅ CAN BE AUTOWIRED (Remove from Application.php)

| Service | Reason | Lines Saved |
|---------|--------|-------------|
| **OrganisationMapper** | Only type-hinted: IDBConnection, LoggerInterface, IEventDispatcher | ~10 |
| **OrganisationService** | Only type-hinted + optional ?SettingsService | ~15 |
| **PropertyValidatorHandler** | No constructor or only type-hinted | ~10 |
| **SchemaMapper** | Once deps above are autowired, this can be too | ~15 |
| **ObjectEntityMapper** | Once SchemaMapper is autowired | ~20 |
| **RegisterMapper** | Once ObjectEntityMapper is autowired | ~15 |
| **Most other mappers** | Already noted as autowirable in comments | ~0 (already removed) |
| **Chat handlers** | Only type-hinted params | ~60 |
| **Settings handlers** | Most have only IConfig + string appName, but... | See below |

### ❌ MUST BE MANUALLY REGISTERED

| Service | Reason | Keep |
|---------|--------|------|
| **Settings handlers** | Require `'openregister'` string param | Yes, BUT can simplify |
| **VectorizationService** | Complex factory logic for strategy registration | Yes |
| **SaveObject** | Requires `new ArrayLoader()` | Yes |
| **SettingsService** | Uses ContainerInterface (needs refactoring) | Yes (for now) |
| **Circular dependency services** | If circular deps truly exist | Verify first! |

## Implementation Plan

### Phase 1: Verify Autowiring Works (Test First!)
1. Comment out OrganisationMapper registration
2. Test if app still works
3. If yes → continue, if no → investigate why

### Phase 2: Remove Core Mappers (50-60 lines)
1. ✅ Remove OrganisationMapper
2. ✅ Remove OrganisationService  
3. ✅ Remove PropertyValidatorHandler registration (if exists)
4. ✅ Remove SchemaMapper
5. ✅ Remove ObjectEntityMapper
6. ✅ Remove RegisterMapper

**Estimated reduction**: ~85 lines

### Phase 3: Remove Chat Handlers (60 lines)
All Chat handlers use only type-hinted params:
- ContextRetrievalHandler
- ToolManagementHandler
- ResponseGenerationHandler
- MessageHistoryHandler
- ConversationManagementHandler

**Estimated reduction**: ~60 lines

### Phase 4: Simplify Settings Handlers (100 lines)
Current pattern for each handler:
```php
$context->registerService(
    ValidationOperationsHandler::class,
    fn($c) => new ValidationOperationsHandler(
        $c->get(ValidateObject::class),
        $c->get(SchemaMapper::class),
        $c->get('Psr\Log\LoggerInterface'),
        $c
    )
);
```

**Problem**: Some have string params or ContainerInterface.

**Options**:
A. Remove handlers that CAN be autowired (those without string params)
B. Create a helper method to reduce boilerplate
C. Use PHP attributes for DI configuration (Nextcloud 28+)

**Estimated reduction**: ~50-80 lines

### Phase 5: Document What Remains
Add clear comments explaining WHY each remaining registration is manual.

## Expected Result

| Metric | Before | After | Improvement |
|--------|--------|-------|-------------|
| register() lines | 654 | ~150-200 | 70% reduction |
| Manual registrations | ~80 | ~15-20 | 75% reduction |
| CouplingBetweenObjects | 99 | ~30-40 | 60% reduction |

## Testing Strategy

After each phase:
1. Run `php occ app:list | grep openregister` - should show enabled
2. Test basic functionality (create object, search, etc.)
3. Check logs for DI errors: `docker logs master-nextcloud-1 | grep -i "could not resolve"`
4. Run PHPUnit tests if available

## Risks & Mitigation

### Risk: Circular Dependencies
**Symptom**: "Could not resolve dependency X while resolving Y"
**Mitigation**: Keep those services manually registered, document why

### Risk: Breaking Existing Functionality
**Symptom**: 500 errors, missing service exceptions
**Mitigation**: Test incrementally, one service at a time

### Risk: Performance Impact
**Symptom**: Slower app initialization
**Mitigation**: Nextcloud's autowiring is cached, should be same or faster

## Commands

```bash
# Enable app
php occ app:enable openregister

# Check for DI errors
docker logs master-nextcloud-1 2>&1 | grep -i "ServerContainer"

# Clear DI cache
docker exec -u 33 master-nextcloud-1 php occ cache:clear

# Test specific controller
curl -u admin:admin http://master-nextcloud-1/index.php/apps/openregister/api/objects
```

## Implementation

Start with Phase 1 - test OrganisationMapper autowiring before proceeding.

