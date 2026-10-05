---
kind: code
depends_on: []
---

# Proposal: object-organisation-from-a-property

## Why

This is a supporting change: it closes no row itself, and it is what row 12.34 needs from OpenRegister.

Row 12.34, "A publication names the organisational unit it was published for, and rights can be scoped to that unit", is `partial` (`build-plan/gaps.tsv`). A publication's `organization` property names the unit, while the rights OpenRegister's multitenancy enforces follow `@self.organisation`, which `SaveObject` stamps from the caller's active organisation (`SaveObject.php`, the `getOrganisationForNewEntity()` fallback) or from a client-supplied `@self.organisation` the caller is a member of. Nothing ties the two together, so a publication can name unit A while its rights follow unit B. `opencatalogi/publications-reference-the-shared-organisation` (wave 2) adds the opencatalogi half and depends on this.

## What changes

- A schema may declare `x-openregister-organisation: {"fromProperty": "<property>"}`. The annotation is added to `Schema::ANNOTATION_VOCABULARY` and validated at import: the property must exist and be a string or a reference.
- On create and update of an object of such a schema, `SaveObject` resolves the property's value (a uuid, or a reference whose `uuid` or `id` it reads) to an OpenRegister `Organisation` and sets `@self.organisation` to it. The client cannot set `@self.organisation` separately: a different value is refused with 422.
- The caller must be an administrator or a member of the organisation the property names; otherwise the write is refused with 403. It never falls back to the caller's active organisation, because that fallback is exactly the disagreement this change removes.
- An empty property gets the organisation `SaveObject` stamps today, written back into the property, so the two agree from the first save.
- A value that names no existing organisation is refused with 422.
- `occ openregister:organisation:reconcile --register <r> --schema <s> [--apply]` lists objects whose property and `@self.organisation` disagree and, with `--apply`, sets `@self.organisation` from the property, writing an audit row per object.

## What does not change

- Schemas without the annotation: today's stamping is unchanged.
- How multitenancy enforces rights on `@self.organisation`.

## Dependencies and absent apps

- None blocking. Consumed by `opencatalogi/publications-reference-the-shared-organisation` (wave 2), which declares `fromProperty: "organization"` on the publication schema. Without opencatalogi no shipped schema declares the annotation and behaviour is unchanged.

## Wave and decision

Wave 1, size S, supporting. No decision bears on it. Supports 12.34.
