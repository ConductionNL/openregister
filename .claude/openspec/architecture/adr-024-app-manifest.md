# ADR-024: App Manifest (fleet-wide adoption)

## Status
Proposed

## Date
2026-05-03

## Context

`@conduction/nextcloud-vue` ships a manifest renderer end-to-end (schema,
loader, validator, `CnAppRoot` / `CnAppNav` / `CnPageRenderer` components,
four-tier adoption guide, ≥1 production consumer). Spec at
`nextcloud-vue/openspec/changes/add-json-manifest-renderer/specs/json-manifest-renderer/spec.md`
(17 REQ-JMR-* requirements). Decidesk is the only adopter today (Tier 4,
39 pages, v0.3.0 — `decidesk/src/manifest.json`).

Without a fleet-wide convention, the manifest stays a one-off:

- New apps re-roll their own router config + sidebar + dependency-check
  + page dispatch logic instead of consuming `CnAppRoot`.
- Cross-app admin UIs ("App Builder" — admin tweaks menu order, hides
  pages, overrides locale) have nothing to plug into per-app.
- Consumer apps that *want* the renderer don't know which Tier to start
  at, where the manifest file lives, or what the validation contract is.
- Filename / location drift will set in (every app picks its own path)
  unless the convention is pinned.

This ADR codifies the convention; the renderer itself stays governed by
`add-json-manifest-renderer` in nextcloud-vue.

## Decision

**Every Conduction app SHOULD ship a `src/manifest.json` validated
against the canonical schema. New apps MUST adopt at least Tier 1 from
inception.**

Specifically:

1. **Schema source** — the canonical schema is
   `@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`.
   Apps MUST set `$schema` to the published URL of this file (for
   editor auto-validation); they MUST NOT fork or duplicate the schema.
2. **Location** — `src/manifest.json` (next to `main.js` /
   `App.vue`). Bundled into the build; served by webpack as a static
   import.
3. **Loader contract** — every consumer's `main.js` MUST
   `import bundled from './manifest.json'` and pass it to
   `useAppManifest(appId, bundled)`. The bundled-only path is
   CSP-clean; the backend-merge hook is opt-in.
4. **Backend endpoint convention** — apps MAY implement
   `GET /index.php/apps/{appId}/api/manifest` returning a partial
   override blob (admin-customised menu order, hidden pages, locale
   overrides). Apps that don't yet implement it return 404; the loader
   silently falls back. Response shape is a partial of the canonical
   schema (top-level `additionalProperties: false`).
5. **Validation gate** — every consumer MUST add
   `npm run check:manifest` to its `package.json` scripts (calls
   `validateManifest` from the library at build time); CI fails on
   schema errors. Mirror the pattern of nextcloud-vue's `check:docs`.
6. **i18n** — `label` and `title` are translation keys consumed by the
   app's own `t()` function; the manifest itself ships keys, not
   strings. Aligns with ADR-007 and the i18n-* shared specs.
7. **Versioning** — `manifest.version` follows semver of content; the
   library-side schema version is in the schema's `"version"` field.
   Apps set `manifest.version` to `0.x.y` while iterating; bump to
   `1.0.0` when the manifest stabilises.
8. **Tier choice** — adoption is tiered (1 = `useAppManifest` only;
   2 = + `CnPageRenderer`; 3 = + `CnAppNav`; 4 = full `CnAppRoot`
   shell). Each app picks its own Tier and may upgrade incrementally.
9. **Per-app adoption** — each app gets its own openspec change
   (`{app}-adopt-manifest`) referencing this ADR. The change MUST
   include: (1) generated `src/manifest.json` from the existing router,
   (2) an explicit Tier choice, (3) a regression test confirming all
   routes still resolve, (4) reviewer sign-off that the manifest does
   not duplicate or contradict the canonical schema.
10. **Apps that should NOT depend on OpenRegister** — mydash and
    nldesign MUST NOT list `openregister` in `manifest.dependencies`.
    Per `feedback_mydash-no-or-dependency.md`, mydash is a BI surface
    that talks to OR via runtime GraphQL only; nldesign is a theme
    layer. Other apps SHOULD list every cross-app dependency the user
    needs installed for the app to function.

## Consequences

- The `CnAppRoot` shell becomes the default UI shell across the fleet;
  per-app router boilerplate shrinks toward zero.
- Cross-app admin tooling ("App Builder", `/api/manifest` consumers,
  manifest-aware audits) has a stable contract to target.
- Reviewers gain a fleet-wide gate: a PR adding routes that aren't
  reflected in `src/manifest.json` is treated as drift. (Pairs with
  ADR-029 route-reachability gate.)
- Migration order recommendation (cheapest → highest-value):
  `mydash` → `larpingapp` / `softwarecatalog` → `openregister` →
  remaining apps. Decidesk is already Tier 4 and serves as the
  reference.
- App-manifest extensions (e.g. `theme: { primary, accent, logoUrl }`,
  `roles[]`) are out of scope for v1; revisit in a successor ADR if
  patterns surface during adoption.
- The `type` enum (`index | detail | dashboard | custom`) is closed;
  new built-in types require a library-level openspec change in
  nextcloud-vue, not an app-side override. Apps register custom page
  types via the `customComponents` prop on `CnAppRoot`.

## See also

- `nextcloud-vue/openspec/changes/add-json-manifest-renderer/` — the
  library-side spec the renderer ships against.
- `decidesk/src/manifest.json` — canonical Tier-4 example.
- ADR-022 (apps consume OR abstractions) — the manifest is the FE
  side of the same principle.
- ADR-029 (route-reachability gate) — pairs with the manifest's
  `pages[]` declaration.
