# Application.php Dependency Injection Analysis

## Current State

- **Manual Registrations**: 29 services
- **register() Method**: 654 lines
- **Coupling**: 99 dependencies
- **Complexity**: CyclomaticComplexity = HIGH

## Goal

Reduce complexity by leveraging Nextcloud's autowiring capabilities.

## Analysis of 29 Manual Registrations

### ✅ Can Be AUTOWIRED (Remove from Application.php)

These have only type-hinted constructor parameters:

| Service | Constructor Parameters | Status |
|---------|----------------------|---------|
| **OrganisationMapper** | IDBConnection, LoggerInterface, IEventDispatcher | ✅ AUTOWIRE |
| **AuditTrailMapper** | IDBConnection, ObjectEntityMapper | ✅ AUTOWIRE |
| **OrganisationService** | All type-hinted (+ optional nullable) | ✅ AUTOWIRE |
| **Chat Handlers** (5) | All type-hinted | ✅ AUTOWIRE (if no circular deps) |
| - ContextRetrievalHandler | VectorEmbeddings, IndexService, Logger | ✅ AUTOWIRE |
| - ToolManagementHandler | AgentMapper, ToolRegistry, Logger | ✅ AUTOWIRE |
| - ResponseGenerationHandler | SettingsService, ToolManagementHandler, Logger | ✅ AUTOWIRE |
| - MessageHistoryHandler | MessageMapper, ConversationMapper, Logger | ✅ AUTOWIRE |
| - ConversationManagementHandler | Multiple mappers + services | ⚠️ CHECK CIRCULAR |
| **GitHubHandler** | Needs verification | ⚠️ CHECK |
| **GitLabHandler** | Needs verification | ⚠️ CHECK |

**Estimated Removal**: 8-12 services

### ❓ Need to CHECK

These might have scalar parameters or special logic:

| Service | Reason | Check |
|---------|--------|-------|
| **Settings Handlers** (8) | Likely have 'openregister' string param | ❌ KEEP |
| **ConfigurationService** | Needs verification | ⚠️ CHECK |
| **VectorizationService** | Has factory logic (strategy registration) | ❌ KEEP |
| **SaveObject** | Requires ArrayLoader instance | ❌ KEEP |
| **SolrDebugCommand** | Command registration | ❌ KEEP |

### ❌ MUST Keep Manual Registration

These have good reasons:

| Service | Reason |
|---------|--------|
| **SchemaMapper** | Circular dependency order |
| **ObjectEntityMapper** | Circular dependency order + many handlers |
| **RegisterMapper** | Circular dependency order |
| **CacheHandler** | Lazy loading to break circular dependency |
| **SettingsService** | Currently uses ContainerInterface |
| **SearchBackendInterface** | Interface binding to SolrBackend |
| **Settings Handlers** (8) | Have scalar 'openregister' parameter |
| **VectorizationService** | Complex factory logic |
| **SaveObject** | Requires ArrayLoader instance |
| **SolrDebugCommand** | Command |

**Must Keep**: ~18 services

## Recommended Changes

### Phase 1: Remove Obvious Autowirable Services (Quick Win)

**Remove these** (save ~30-50 lines):

```php
// ❌ REMOVE - Can autowire
$context->registerService(
    OrganisationMapper::class,
    function ($container) {
        return new OrganisationMapper(
            $container->get('OCP\IDBConnection'),
            $container->get('Psr\Log\LoggerInterface'),
            $container->get('OCP\EventDispatcher\IEventDispatcher')
        );
    }
);

// ❌ REMOVE - Can autowire  
$context->registerService(
    AuditTrailMapper::class,
    function ($container) {
        return new AuditTrailMapper(
            $container->get('OCP\IDBConnection'),
            $container->get(ObjectEntityMapper::class)
        );
    }
);

// ❌ REMOVE - Can autowire (nullable optional is fine)
$context->registerService(
    OrganisationService::class,
    function ($container) {
        return new OrganisationService(
            organisationMapper: $container->get(OrganisationMapper::class),
            userSession: $container->get('OCP\IUserSession'),
            session: $container->get('OCP\ISession'),
            config: $container->get('OCP\IConfig'),
            appConfig: $container->get('OCP\IAppConfig'),
            groupManager: $container->get('OCP\IGroupManager'),
            userManager: $container->get('OCP\IUserManager'),
            logger: $container->get('Psr\Log\LoggerInterface')
        );
    }
);
```

### Phase 2: Check & Remove Chat Handlers

Check if Chat handlers have circular dependencies. If not, remove all 5 registrations (~50-70 lines).

### Phase 3: Verify Configuration Handlers

Check GitHubHandler, GitLabHandler, ConfigurationService constructors.

## Expected Impact

### Before
```
register() method: 654 lines
Manual registrations: 29
```

### After Phase 1
```
register() method: ~600 lines (-54 lines, -8%)
Manual registrations: 26 (-3)
```

### After All Phases (optimistic)
```
register() method: ~480 lines (-174 lines, -27%)
Manual registrations: 17-18 (-11 to -12)
```

## Implementation Plan

1. ✅ **Verify constructors** for services to remove
2. ✅ **Comment out** registrations (don't delete yet)
3. ✅ **Test** that app still works
4. ✅ **Remove** commented code if test passes
5. ✅ **Update** comments explaining what's autowired

## Testing Strategy

```bash
# 1. Clear caches
php occ app:disable openregister
php occ app:enable openregister

# 2. Test basic functionality
php occ openregister:test # if exists

# 3. Check for DI errors in logs
docker logs nextcloud-container | grep -i "dependency\|autowir\|inject"

# 4. Test via web UI
# Navigate to OpenRegister and verify functionality
```

## Files to Modify

1. `lib/AppInfo/Application.php` - Remove autowirable service registrations

## Risks

- **Medium Risk**: Chat handlers might have circular dependencies
- **Low Risk**: OrganisationMapper, AuditTrailMapper are safe to autowire
- **Very Low Risk**: OrganisationService autowiring

## Next Steps

1. Start with OrganisationMapper (safest)
2. Test
3. Continue with AuditTrailMapper  
4. Test
5. Continue with OrganisationService
6. Test
7. Tackle Chat handlers if tests pass

## Alternative: Extreme Autowiring

If we're confident, we could remove ALL autowirable services at once and test. Nextcloud's DI is robust and will throw clear errors if something can't be resolved.

**Pros**: Faster, cleaner
**Cons**: Harder to debug if multiple services fail

## Conclusion

**Recommendation**: Start with Phase 1 (3 services) as a quick win, then expand based on test results.

**Expected Outcome**: 
- Simpler Application.php
- Easier maintenance
- Leverages Nextcloud's DI properly
- Still keeps manual registration where truly needed

