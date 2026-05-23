---
status: redirect
---
# DSO Omgevingsloket Integration

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Integrations / External adapters

**Rationale:** Adapter  
_Source: /tmp/ia-mydash-openregister.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose
This spec is a redirect stub. The canonical specification for DSO Omgevingsloket integration is owned by the Procest app at `procest/openspec/specs/dso-omgevingsloket/spec.md`. This stub exists to preserve the spec slug locally and MUST NOT be treated as authoritative.

## Requirements

### Requirement: Consult the canonical dso-omgevingsloket spec
Implementers MUST consult the canonical specification owned by Procest instead of treating this stub as authoritative.

#### Scenario: Locating the canonical spec
- **WHEN** a developer needs the requirements for DSO Omgevingsloket integration
- **THEN** they MUST refer to `procest/openspec/specs/dso-omgevingsloket/spec.md`
- **AND** they MUST NOT derive normative behavior from this stub
