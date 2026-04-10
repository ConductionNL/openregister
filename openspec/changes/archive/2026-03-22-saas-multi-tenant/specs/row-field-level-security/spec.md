# Row and Field Level Security (Delta for SaaS Multi-Tenant)

## MODIFIED Requirements

### Requirement: Schemas MUST support row-level security rules via conditional authorization matching
Schema authorization blocks MUST accept conditional rules that filter objects based on the current user's context (group membership, identity, organisation) and the object's own field values. Conditional rules use the structure `{ "group": "<group>", "match": { "<property>": "<value-or-operator>" } }` where the user must qualify for the group AND the object must satisfy all match conditions. **In SaaS mode, the `_organisation` filter MUST always be applied as a hard boundary before any RLS rules are evaluated, ensuring that RLS rules can only further restrict access within a tenant, never grant cross-tenant access. Even admin users with admin override enabled MUST NOT bypass the organisation boundary through RLS rules.**

#### Scenario: Restrict access by department field using group + match
- **GIVEN** schema `meldingen` has authorization: `{ "read": [{ "group": "behandelaars", "match": { "afdeling": "sociale-zaken" } }] }`
- **AND** user `jan` is in group `behandelaars`
- **AND** melding `melding-1` has `afdeling: "sociale-zaken"`
- **AND** melding `melding-2` has `afdeling: "ruimtelijke-ordening"`
- **WHEN** `jan` lists meldingen
- **THEN** `MagicRbacHandler::applyRbacFilters()` MUST add a SQL WHERE clause: `t.afdeling = 'sociale-zaken'`
- **AND** `jan` MUST see `melding-1` but NOT `melding-2`
- **AND** filtering MUST happen at the database query level (not post-fetch)

#### Scenario: Organisation boundary is enforced before RLS evaluation
- **GIVEN** schema `meldingen` has authorization: `{ "read": [{ "group": "behandelaars" }] }`
- **AND** user `jan` is in group `behandelaars` with active Organisation `org-A`
- **AND** melding `melding-1` belongs to Organisation `org-A`
- **AND** melding `melding-2` belongs to Organisation `org-B`
- **WHEN** `jan` lists meldingen
- **THEN** `MagicOrganizationHandler::applyOrganizationFilter()` MUST filter to `_organisation = 'org-A'` BEFORE `MagicRbacHandler::applyRbacFilters()` executes
- **AND** `jan` MUST see `melding-1` but MUST NOT see `melding-2`
- **AND** this MUST hold true even if `jan` is an admin with admin override enabled

#### Scenario: Admin override does NOT bypass organisation boundary in SaaS mode
- **GIVEN** the multitenancy configuration has `adminOverride: true` and `saasMode: true`
- **AND** admin user `superadmin` has active Organisation `org-A`
- **WHEN** `superadmin` queries objects
- **THEN** the organisation filter MUST still restrict results to `org-A` objects only
- **AND** admin override MUST only apply to RLS/RBAC rules within the organisation boundary
- **AND** a log entry MUST indicate that SaaS mode prevented admin override of organisation boundary
