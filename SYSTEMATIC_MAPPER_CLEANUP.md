# 🔧 Systematic Mapper Cleanup - Action Plan

## User Requirements
1. ✅ Mappers must NOT inject Services or Handlers
2. ✅ Active organization comes from USER SESSION (not database calls in mapper)
3. ✅ Fix ALL violations NOW (not defer)

## Strategy
**Remove ALL service/handler injections from ALL mappers**

Organization filtering should be:
- Retrieved from session in SERVICE layer
- Passed as PARAMETER to mapper methods
- OR use MultiTenancyTrait if it handles this properly

## Mappers to Clean (Priority Order)

### 1. ObjectEntityMapper ✅ DONE
- Removed 7 handlers
- Still has: OrganisationService, MySQLJsonService

### 2-10. Other Mappers (OrganisationService)
- AgentMapper
- ApplicationMapper
- ConfigurationMapper
- EndpointMapper
- RegisterMapper
- SchemaMapper (+ PropertyValidatorHandler)
- SourceMapper
- ViewMapper (+ CacheHandler)
- WebhookMapper

## Execution Plan
1. Remove OrganisationService from each mapper constructor
2. Remove the property declaration
3. Check what methods use it
4. Update Application.php registration
5. Document which services need updating (don't update services now, just document)

Let's proceed systematically!

