# Specter regen note — 2026-04-29

This file documents what changed in OpenRegister between **2026-04-27** and **2026-04-29** that the Specter spec-generation pipeline must pick up on its next run.

## What changed in the platform

`openspec/platform-capabilities.md` is the single source of truth that Specter reads at Step 0 (`specter-prepare-context`) when generating a context brief for a new spec. The catalog now reflects:

### Schema annotations (Section 1)

| Row | Status before | Status now | What's new |
|---|---|---|---|
| `x-openregister-lifecycle` | proposed | implemented | Pilot live across decidesk Meeting/Motion/Amendment/Minutes, docudesk signingRequest, procest 7 schemas, softwarecatalog 5 schemas. `LifecycleGuardInterface` for app-side authorization. |
| `x-openregister-aggregations` | proposed | implemented | Live `GET /api/objects/aggregations/{register}/{schema}/{name}`. PHP-side runner; backend-native execution still on the backlog. |
| `x-openregister-calculations` | proposed | implemented | Both materialise:true (on-save listener) AND materialise:false (render-time when `_extend=calculations`). Dotted prop paths incl. `@self.created`, `@self.updated` work. `occ openregister:rematerialise-calculations` command shipped (gated on the upstream `<commands>` info.xml fix). |
| `x-openregister-notifications` | proposed | implemented v1 | INotificationManager + bundled INotifier. Triggers: created/updated/transition (with action filter). Recipients: users/field. Channel: nc-notification. Subject template `{{prop}}` interpolation. |

### NC-app integration providers (Section 2)

| Provider | Status before | Status now |
|---|---|---|
| Activity provider | proposed | implemented |
| Calendar provider | proposed | implemented (multi-tenancy bypass when listing schemas; per-object ACLs gate event content) |
| Contacts actions | proposed | partial (API only — provider registration deferred) |
| Mail sidebar | proposed | partial (routes only — EmailService rewrite onto `_mail` JSON column needed) |
| Mail Smart Picker | proposed | implemented |
| File actions | proposed | partial (skeleton; 38 follow-up tasks deferred) |
| Profile actions | proposed | implemented |

## What Specter should do on its next run

1. **Re-load `openspec/platform-capabilities.md`** at Step 0. Every `proposed` row that's now `implemented` must surface as a positive signal in the linter (`scripts/lib/lint-spec-for-redundant-crud.py`) — specs that propose a state-machine controller, an analytics service, or a notification dispatcher must be redirected to the annotation.

2. **Re-run any spec generation queued before 2026-04-29** that fell into one of these buckets:
   - Spec proposed `*Service::transition()` → redirect to `x-openregister-lifecycle`
   - Spec proposed `*AnalyticsService::*` → redirect to `x-openregister-aggregations` + `x-openregister-calculations`
   - Spec proposed `*NotificationService::*` for object events → redirect to `x-openregister-notifications`
   - Spec proposed Mail/Calendar/Contacts integration → redirect to the implemented provider

3. **Sweep older specs** under `openspec/changes/archive/` whose proposal predicted these capabilities as `proposed` — those archives are correct as historical records but the spec-writer skill should not pull them as templates.

## Reverse-spec follow-up (per app)

Apps that have lifecycle / aggregations / calculations / notifications in code but not yet declared via the annotation should be retrofitted via `/opsx-reverse-spec`:

- **larping** — no transition matrices found in the prior scan, but its schemas (Character / Skill / etc.) likely have status enums worth declaring.
- **openconnector** — no transition matrices found; check publication / source / endpoint schemas for status fields.
- **opencatalogi** — only catalog + listing migrated so far; remaining schemas (publication, organisation, listing-item, source) have status fields that could get lifecycles.

Procest (7 schemas) and softwarecatalog (5 schemas) were migrated in this round.

## What's NOT yet in the catalog and shouldn't be referenced

- **Backend-native aggregations** (Postgres GROUP BY / Solr facets / ES aggs)
- **Notification v2 features**: Webhook auto-create, scheduled triggers, threshold triggers, group/relation/object-acl/expression recipients, email/Talk/Activity channels
- **EmailService rewrite onto `_mail` JSON column**
- **ContactsMenuProvider safe registration** (currently triggers eager-eval at startup)

When Specter generates a spec referencing any of these, the linter should still treat them as separate change items — they're tracked in the OR backlog but not yet platform-provided.
