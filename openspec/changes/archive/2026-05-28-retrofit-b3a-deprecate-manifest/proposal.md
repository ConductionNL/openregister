# Retrofit — b3a-deprecate-manifest

Investigates 7 "Bucket 3a" REQs flagged by the coverage scanner as having NO
annotated implementation. Each was read against its spec, grepped against the
codebase, classified, and (where implemented) annotated with an `@spec` tag.

This is an annotations-only retrofit. No behavior changed. No new REQs drafted.

## Per-REQ classification

| REQ | Title | Classification | Evidence |
| --- | --- | --- | --- |
| `deprecate-published-metadata#REQ-2` | Frontend Copy Modal Cleanup | SATISFIED-BY-ABSENCE | `src/modals/object/CopyObject.vue:135-149` and `src/modals/object/MassCopyObjects.vue:197-208` strip `@self.{id,uuid,uri,created,updated,version,files,relations,folder,size,deleted}` — no `published`/`depublished` keys deleted (they no longer exist). The cleanup the REQ asked for is complete. No code to annotate. |
| `deprecate-published-metadata#REQ-4` | Import UI Cleanup | SATISFIED-BY-ABSENCE | `src/modals/register/ImportRegister.vue:256-307` declares import toggles `includeObjects`, `validation`, `events`, `rbac`, `multi` — the "Auto-publish imported objects" toggle has been removed. No code to annotate. |
| `deprecate-published-metadata#REQ-5` | MultiTenancyTrait Documentation | IMPLEMENTED | `lib/Db/MultiTenancyTrait.php:234,242` — published-bypass docs are scoped to "Register/Schema entities only"; object-level published bypass references are gone, Register/Schema bypass docs remain. Annotated. |
| `deprecate-published-metadata#REQ-6` | Deprecation Warnings | IMPLEMENTED | `lib/Service/Object/SaveObject/MetadataHydrationHandler.php:106-124` logs a `LoggerInterface::warning` for each of `objectPublishedField`, `objectDepublishedField`, `autoPublish` schema config keys, recommending RBAC `$now`. Annotated. |
| `openregister-app-manifest#MAN-002` | Manifest declares zero Conduction-app dependencies | MISSING | `src/manifest.json` does not exist. `tests/validate-manifest.js:120-125` explicitly treats OR as the foundation app and skips when no manifest. With no manifest there is no `dependencies` field to set to `[]`. Cannot be satisfied until the manifest is authored (REQ-OR-MAN-001). |
| `openregister-app-manifest#MAN-007` | Build gate validates the manifest | IMPLEMENTED | `package.json` declares `check:manifest` → `tests/validate-manifest.js`, included in the `check:specs` composite, and wired into CI via `.github/workflows/spec-validation.yml` (`npm run check:specs`). The validator Ajv-validates against the canonical `@conduction/nextcloud-vue` schema, prints error paths, and exits non-zero on schema violation. Annotated. |
| `openregister-app-manifest#MAN-008` | Manifest version reflects the adoption tier | MISSING | No `src/manifest.json` → no top-level `version` field. Cannot be `0.1.0`/`0.2.0` until the manifest exists (REQ-OR-MAN-001/005). |

Summary: 2 IMPLEMENTED, 0 PARTIAL, 2 MISSING, 2 SATISFIED-BY-ABSENCE (REQ-3 not in this batch).

## Notes

- The `deprecate-published-metadata` capability is marked `status: implemented`
  in its canonical spec. REQ-2 and REQ-4 are "cleanup" requirements: the code
  they asked to *remove* is gone, so they are satisfied by absence rather than
  by an annotatable implementation.
- `autoPublish` still appears in `lib/Service/Object/SaveObject/FilePropertyHandler.php`
  (file-share publish), which is explicitly **out of scope** per the spec
  ("File publish/depublish — Nextcloud share management, `autoPublish` in
  FilePropertyHandler"). Not flagged.
- The two MISSING manifest REQs depend on `REQ-OR-MAN-001` (author
  `src/manifest.json`), which the upstream change `openregister-adopt-app-manifest`
  has not yet landed. The build gate (MAN-007) is in place ahead of the manifest
  and skips cleanly until the manifest exists — this is intentional.

Source: /tmp/or-scan/b3a-deprecate-manifest.json. See retrofit playbook.
