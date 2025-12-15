# Systematic Fix Plan

## Current Status
App won't load due to circular dependencies.

## Root Cause
**11 handlers** inject services, violating the Services → Handlers pattern.

## Strategy

### Phase 1: Diagnostic (5 min)
1. Temporarily comment out problematic handler injections in ObjectService
2. Test if app loads
3. This confirms handlers are the issue

### Phase 2: Fix Simple Handlers (30 min)
Fix handlers with minimal logic:
1. VectorizationHandler → Remove VectorizationService, implement placeholder
2. MergeHandler → Remove FileService, implement placeholder
3. PerformanceOptimizationHandler → Remove OrganisationService
4. Test app loads

### Phase 3: Fix Core Handlers (1-2 hours)
5. DeleteObject → Remove FileService
6. GetObject → Remove FileService, SettingsService
7. PermissionHandler → Remove OrganisationService
8. SaveObjects → Remove OrganisationService
9. Test app loads

### Phase 4: Fix Complex Handlers (2-3 hours)  
10. SaveObject → Remove 3 services, refactor logic
11. SearchQueryHandler → Remove 2 services
12. PerformanceHandler → Remove CacheHandler
13. Final test

## Decision
Start with Phase 1 diagnostic to confirm the approach will work.

