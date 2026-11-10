## 1. Resolve relation placeholders to names

- [x] Add `resolveRelationDisplayName(string $value): ?string` to `AnnotationNotificationDispatcher` — UUID-shaped values resolved via `ObjectService::find(_rbac: true)` to the object's name; null for non-UUID / unresolvable / nameless; per-instance cache.
- [x] `interpolate()` substitutes the resolved name for `{{prop}}` data values, falling back to the raw value.
- [x] Back-compat: non-UUID placeholders, absent ObjectService, and unresolvable UUIDs keep today's behaviour.

## Acceptance Criteria

- `{{client}}` (a relation UUID) renders the related object's display name in both title and body.
- A non-relation placeholder (text/number) is unchanged.
- An unresolvable UUID renders the raw value (no error).

## Quality reminders

- phpcs/psalm clean (8.3 container); EUPL-1.2 SPDX + @spec on changed methods.
- No forbidden patterns; named args for OR calls.
