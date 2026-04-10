# Proposal: zgw-api-mapping

## Summary
Move OpenConnector's Twig-based mapping engine into OpenRegister as a core capability and expose Dutch ZGW (Zaakgericht Werken) compliant API endpoints on English-language datasets through bidirectional property and value mapping. ZGW mapping configuration and default mappings are stored in Procest.

## Motivation
Dutch municipalities require ZGW-compliant APIs (Zaken, Catalogi, Besluiten, Documenten) to integrate with the GEMMA ecosystem. Procest stores case management data in OpenRegister using English property names (e.g., `case`, `status`, `deadline`), but ZGW clients expect Dutch property names and values (e.g., `zaak`, `status`, `uiterlijkeEinddatumAfdoening`). Rather than maintaining dual schemas or hardcoding translations, we need a mapping engine that translates on-the-fly in both directions.

The mapping engine currently lives in OpenConnector, but mapping is a fundamental capability of how OpenRegister serves data through different API profiles. Moving it into OpenRegister:
- Makes mapping a first-class citizen of the data layer
- Enables any app (not just OpenConnector) to define API profiles with property/value translation
- Reduces coupling between OpenConnector and data transformation logic
- Positions OpenRegister as the single source of truth for both data storage and data presentation

## Affected Projects
- [ ] Project: `openregister` -- Receives the mapping engine (MappingService, Mapping entity, MappingMapper, MappingRuntime), new ZGW API routes (ZgwController), and generic mapping infrastructure
- [ ] Project: `openconnector` -- Removes its own mapping engine, depends on OpenRegister's MappingService instead
- [ ] Project: `procest` -- Stores ZGW mapping configuration (ZgwMapping schema), ships default mappings for all 12 ZGW resources, provides admin UI for mapping management

## Scope

### In Scope
- Moving the mapping engine (MappingService, Mapping entity, MappingMapper, MappingRuntime) from OpenConnector to OpenRegister
- ZGW API routes in OpenRegister (`/api/zgw/{zgwApi}/v1/{resource}/{uuid?}`)
- Bidirectional property mapping (English to Dutch outbound, Dutch to English inbound) using Twig templates
- Value mapping for enum fields (e.g., confidentiality levels) via `zgw_enum` Twig filter
- ZGW-style pagination (`count`, `next`, `previous`, `results`)
- ZGW query parameter mapping (Dutch parameter names to English filter fields, URL-to-UUID extraction)
- Default ZGW mappings for all 12 resources (Zaak, ZaakType, Status, StatusType, Resultaat, ResultaatType, Rol, RolType, Eigenschap, Besluit, BesluitType, InformatieObjectType)
- ZGW URL references (UUID values expanded to full ZGW URLs on outbound, parsed back to UUIDs on inbound)
- Mapping administration tab in Procest admin settings

### Out of Scope
- Full ZGW compliance certification (this is a compatibility layer, not a reference implementation)
- Autorisaties API (authorization/scopes) -- use Nextcloud's auth system
- Notificaties API (ZGW notifications) -- use OpenRegister's CloudEvents system instead
- ZGW-to-ZGW synchronization with external OpenZaak instances (separate concern)

## Approach
1. **Move mapping engine** -- Migrate MappingService, Mapping entity, MappingMapper, and MappingRuntime from OpenConnector to OpenRegister, preserving all existing functionality
2. **Update OpenConnector** -- Replace internal mapping references with dependency on OpenRegister's mapping engine
3. **Create ZGW routes** -- Add ZgwController in OpenRegister that dispatches to the correct schema based on ZGW resource type and mapping configuration
4. **Implement bidirectional mapping** -- Outbound (English to Dutch) for API responses, inbound (Dutch to English) for incoming requests
5. **Add value mapping** -- Implement `zgw_enum` Twig filter for translating enum values between English and Dutch
6. **ZGW pagination and query params** -- Wrap OpenRegister pagination in ZGW HAL-style format, translate query parameter names
7. **Default mappings in Procest** -- Ship default mapping definitions for all 12 ZGW resource types based on Procest's existing schemas
8. **Admin UI** -- Add ZGW mapping administration tab to Procest settings

## Cross-Project Dependencies
- **OpenConnector mapping engine code** -- Source of the mapping engine to be moved (MappingService, Mapping entity, MappingMapper, MappingRuntime with Twig functions)
- **Procest schemas** -- Existing 12 schemas that map to ZGW resource types
- **OpenRegister API system** -- Extended with ZGW route layer
- **Procest admin settings UI** -- Extended with mapping management tab

## Rollback Strategy
- Keep the mapping engine in OpenConnector (revert the move)
- Remove ZGW routes from OpenRegister
- Remove ZGW mapping configuration from Procest
- No data migration involved -- mappings are configuration, not user data

## Capabilities

### New Capabilities
- `openregister-mapping-engine` -- Twig-based property/value mapping as a core OpenRegister service
- `openregister-zgw-routes` -- ZGW-compliant API endpoints served by OpenRegister
- `procest-zgw-mapping-config` -- ZGW mapping definitions stored in Procest configuration
- `procest-zgw-default-mappings` -- Pre-configured mappings for all 12 ZGW resource types
- `procest-zgw-mapping-admin` -- Admin UI for managing ZGW mapping configuration

### Modified Capabilities
- `openconnector-mapping` -- Replaced by dependency on OpenRegister's mapping engine (breaking change for OpenConnector internals, transparent to external consumers)
