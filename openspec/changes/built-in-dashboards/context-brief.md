---
status: redirect
---
# Built-in Dashboards

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Registers / List filter / register property

**Rationale:** Per-register exposed dashboard  
_Source: /tmp/ia-mydash-openregister.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose
This spec is a redirect stub. The canonical specification for built-in dashboards lives in the root openspec (cross-app pattern). This stub exists to preserve the spec slug locally and MUST NOT be treated as authoritative.

## Requirements

### Requirement: Consult the canonical built-in-dashboards spec
Implementers MUST consult the canonical `built-in-dashboards` specification owned by the root openspec instead of treating this stub as authoritative.

#### Scenario: Locating the canonical spec
- **WHEN** a developer needs the requirements for built-in dashboards
- **THEN** they MUST refer to the canonical spec in the root openspec
- **AND** they MUST NOT derive normative behavior from this stub
