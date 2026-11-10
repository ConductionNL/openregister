# Design: i18n Source of Truth

## Reuse analysis

- `lib/Service/TranslationProjectionService.php` already projects from
  schema `translatable: true` properties into the sidecar table; we
  add a third input source (the new `sourceLanguage` modifier).
- `lib/Service/TranslationStatusService.php` already manages `status`
  transitions (draft / machine_translated / human_reviewed /
  approved); we add the `outdated` workflow + the source-change
  trigger.
- `lib/Service/Object/SaveObject.php` already emits an event on
  property changes; we hook the source-change check into the same
  emission path.
- `lib/Service/Object/RenderObject.php` already supports
  `_translations=all` for full language objects; we add
  `_translationMeta=true` for envelope metadata.
- `lib/Middleware/LanguageMiddleware.php` already sets
  `Content-Language` + `X-Content-Language-Fallback` response headers;
  we add `X-Source-Language`.
- `lib/Db/Register.php::getDefaultLanguage()` already returns the
  first element of `Register.languages`; we use it as the
  zero-config fallback.

## Schema property shape

```json
{
  "type": "object",
  "properties": {
    "title": {
      "type": "string",
      "translatable": true,
      "sourceLanguage": "nl"   // optional; defaults to Register.defaultLanguage
    },
    "categoryCode": {
      "type": "string"
      // not translatable; sourceLanguage MUST NOT be present
    }
  }
}
```

The validator rejects `sourceLanguage` on properties without
`translatable: true`. Allowed BCP-47 values mirror the strict pattern
used elsewhere in OR (basic checks: lowercase 2–3 letter primary tag,
optional `-<region>` suffix).

## Object-level override shape

```json
{
  "id": "obj-uuid",
  "title": "Welcome",
  "_translationMeta": {
    "title": {
      "sourceLanguage": "en"
    }
  }
}
```

Object-level overrides are rare (most content follows the schema's
default). They apply only to the named property. The override is
written by clients that author content in a non-default language for a
specific object.

## Migration shape

```sql
ALTER TABLE openregister_translations
    ADD COLUMN source_language VARCHAR(16) NOT NULL DEFAULT '';

UPDATE openregister_translations t
   SET source_language = (
       SELECT COALESCE(r.default_language, 'nl')
         FROM openregister_register r
         JOIN openregister_objects o ON o.register = r.id
        WHERE o.uuid = t.object_uuid
   )
 WHERE source_language = '';
```

The migration ships with a back-fill console command for
multi-million-row installs (`php occ openregister:translations:backfill-source-language --batch-size=1000`).

After a successful back-fill, a follow-up migration removes the
`DEFAULT ''` so future rows are forced to set the column at insert
time.

## Source-change trigger

In `SaveObject`, when persisting an updated object:

```php
foreach ($changedTranslatableProperties as $property => [$oldValue, $newValue]) {
    $sourceLang = $this->resolveSourceLanguage($schema, $object, $property);
    $oldSourceVal = $oldValue[$sourceLang] ?? null;
    $newSourceVal = $newValue[$sourceLang] ?? null;
    if ($oldSourceVal !== $newSourceVal) {
        $this->translationStatusService->markDerivedTranslationsOutdated(
            $object->getUuid(),
            $property,
            $sourceLang
        );
    }
}
```

The check is conservative: only flips status when the source language
value itself changed. Edits to other-language values do not trigger.

## Render envelope shape

```json
{
  "id": "obj-uuid",
  "title": "Welcome",
  "_meta": {
    "languageMeta": {
      "title": {
        "served": "en",
        "sourceLanguage": "nl",
        "isSource": false,
        "status": "approved"
      }
    }
  }
}
```

The envelope is OFF by default (no payload bloat for clients that
don't need it). Opt in with `?_translationMeta=true`. When ON, every
translatable property in the response gets a `languageMeta` entry.

## Open design questions

1. **Object-level override granularity** — the sketch allows
   per-property override. Should we also support a top-level object
   override (`_translationMeta._defaultSourceLanguage = "en"`) that
   applies to every translatable property on the object? Defer; rare
   case, can add in a follow-up if demand surfaces.
2. **Outdated trigger immutability** — once a translation is flipped
   to `outdated`, can a translator reset it to `approved` without
   re-translating, or does the status service force them through
   `human_reviewed` → `approved`? Keep flexible: status is
   independent of the trigger; the trigger only flips OUT of approved
   into outdated. Translators reset manually via existing
   `setStatus()` endpoint.
3. **Bulk back-fill performance** — the back-fill UPDATE is
   register-scoped via JOIN; large multi-tenant installs may need
   batched updates. The console command's `--batch-size` flag
   handles this; default 1000 rows per batch.
4. **`_translationMeta` query parameter naming** — alternative
   spellings (`_lang_meta`, `_translation_meta`, `_translations_meta`).
   Picked `_translationMeta` to match the existing
   `_translationMeta.<prop>.sourceLanguage` body field; consistent
   surface.
