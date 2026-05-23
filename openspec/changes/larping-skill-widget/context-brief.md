---
status: redirect
---
# Larping Skill Widget

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Integrations / External adapters

**Rationale:** Larping proxy  
_Source: /tmp/ia-mydash-openregister.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose
This spec is a redirect stub. The canonical specification for the larping skill widget is owned by LarpingApp at `larpingapp/openspec/specs/larping-skill-widget/spec.md`. This stub exists to preserve the spec slug locally and MUST NOT be treated as authoritative.

## Requirements

### Requirement: Consult the canonical larping-skill-widget spec
Implementers MUST consult the canonical specification owned by LarpingApp instead of treating this stub as authoritative.

#### Scenario: Locating the canonical spec
- **WHEN** a developer needs the requirements for the larping skill widget
- **THEN** they MUST refer to `larpingapp/openspec/specs/larping-skill-widget/spec.md`
- **AND** they MUST NOT derive normative behavior from this stub
