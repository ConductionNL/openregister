# Retrofit — Reverse-Spec `activity-provider`

## Why

The `activity-provider` capability spec describes the planned NC Activity integration (provider, filter, ActivitySettings, event listener) but omits several pieces that already exist in code:

- `lib/Activity/Setting/RegisterSetting.php` ships concrete priority + default-enabled values (`priority=52`, stream-on, mail-off, both user-changeable) that the spec doesn't pin down.
- `lib/Service/Integration/Providers/ActivityProvider.php` adds an Integration-Provider surface (separate from the NC Activity IProvider) that exposes Activity rows linked to an OR object via a `[or:{uuid}]` marker in the activity row's `subject` column — a marker-lookup convention the spec never mentions.
- `src/views/account/sections/ActivitySection.vue` renders a paginated per-user activity feed via `GET /api/user/me/activity`, with offset/limit paging and a type filter — frontend behavior the spec doesn't cover.

This retrofit lifts those observed behaviors into REQs and annotates the methods so the spec ↔ code coverage report (ADR-008) lights up green.

## What Changes

- ADD 5 new requirements to `openspec/specs/activity-provider/spec.md`:
  1. `RegisterSetting` concrete contract values (priority + default flags)
  2. `ActivityProvider` integration-surface contract (id, label, icon, group, required-app, storage-strategy, isEnabled)
  3. `ActivityProvider::list()` marker-lookup convention (`[or:{uuid}]` in `oc_activity.subject`)
  4. `ActivityProvider::health()` response shape
  5. Account `ActivitySection` paginated user-activity feed with type filter
- ANNOTATE 13 of 14 cluster methods with `@spec` tags pointing at the new tasks. The 14th (`makeForm` on the AVG `EditActivityDialog`) belongs to `avg-verwerkingsregister`, not `activity-provider`, and is left for that cluster.

## Impact

- `openspec/specs/activity-provider/spec.md` — gains 5 requirements.
- `lib/Activity/Setting/RegisterSetting.php` — 10 method docblocks annotated.
- `lib/Service/Integration/Providers/ActivityProvider.php` — 2 method docblocks annotated.
- `src/views/account/sections/ActivitySection.vue` — `loadActivity()` docblock annotated.
- No behavior change. Spec ↔ code coverage report only.
