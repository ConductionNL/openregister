# Hypothesis Verification Results

## Question
Are we absolutely sure ObjectService auto-wiring with lazy container->get() is causing the infinite loop?

## Evidence Gathered

### 1. Container References to ObjectService
- **Total found**: 4 active references (excluding comments/backups)
- **Location**: SolrController (2x), ObjectService itself (1x)
- All remaining refs are to **IndexService**, NOT ObjectService ✅

### 2. ObjectService Constructor Analysis
```
Parameters: 43 total
- 22 Handlers
- 6 Services (FileService, SearchTrailService, OrganisationService, FacetService, CacheHandler, SettingsService)  
- 15 Mappers/Interfaces
```

### 3. Object Handlers Using Container
- **11 handlers** in `/lib/Service/Object/` use `$container`
- Need to check if these call ObjectService

### 4. ObjectService Internal Container Usage
- ObjectService ITSELF calls `$this->container->get(IndexService::class)`
- Need to verify this isn't during construction

## Current Confidence Level
**🟡 MEDIUM - Need More Data**

The hypothesis is plausible but NOT proven. We need to:
1. ✅ Check if ObjectService->container->get() happens during __construct
2. ✅ Identify which 11 Object handlers use container
3. ✅ Verify if any of those 11 handlers call ObjectService during THEIR construction

## Alternative Hypotheses
1. **Trait/Interface Issue**: Maybe a trait is doing something unexpected
2. **Mapper Issue**: One of the 15 mappers might have circular dependency
3. **Registration Order**: Maybe services need specific registration order
4. **Nextcloud Bug**: Maybe Nextcloud's DI container has a bug with 43-parameter constructors

## Next Steps
Continue verification to confirm or reject hypothesis.

