# 🚨 Mapper Architecture Violations Found

## Summary
**10 out of 23 mappers** are violating the architecture principle:
> Mappers should NEVER inject Services or Handlers

## Violations by Mapper

### 1. AgentMapper.php
- ❌ `OrganisationService`

### 2. ApplicationMapper.php
- ❌ `OrganisationService`

### 3. ConfigurationMapper.php
- ❌ `OrganisationService`

### 4. EndpointMapper.php
- ❌ `OrganisationService`

### 5. ObjectEntityMapper.php ⚠️ CRITICAL
- ❌ `MySQLJsonService`
- ❌ `OrganisationService`
- ❌ `QueryBuilderHandler`
- ❌ `CrudHandler`
- ❌ `StatisticsHandler`
- ❌ `FacetsHandler`
- ❌ `BulkOperationsHandler`
- ❌ `QueryOptimizationHandler`
- **8 violations in one mapper!**

### 6. RegisterMapper.php
- ❌ `OrganisationService`

### 7. SchemaMapper.php
- ❌ `PropertyValidatorHandler`
- ❌ `OrganisationService`

### 8. SourceMapper.php
- ❌ `OrganisationService`

### 9. ViewMapper.php
- ❌ `OrganisationService`
- ❌ `CacheHandler`

### 10. WebhookMapper.php
- ❌ `OrganisationService`

## Most Common Violation
**`OrganisationService` is injected into 9 mappers!**

This suggests a pattern where mappers are doing multi-tenancy filtering, which should be:
1. Either done in a service layer above the mapper
2. Or passed as parameters to mapper methods

## Action Plan

### Priority 1: Fix ObjectEntityMapper (CRITICAL)
This is causing the current infinite loop. Remove all 8 service/handler injections.

### Priority 2: Remove OrganisationService from 9 mappers
Pattern to follow:
- Move organization filtering logic to services
- Pass organization as parameter to mapper methods
- Use traits for common filtering logic if needed

### Priority 3: Fix remaining violations
- Remove `PropertyValidatorHandler` from `SchemaMapper`
- Remove `CacheHandler` from `ViewMapper`
- Remove `MySQLJsonService` from `ObjectEntityMapper`

## Estimated Impact
- Will require updating ~100+ method calls across the codebase
- But will fix circular dependencies permanently
- Will enforce proper architecture

## Next Steps
1. Fix `ObjectEntityMapper` first (blocks app from loading)
2. Systematically fix each mapper
3. Update all Application.php registrations
4. Test thoroughly

