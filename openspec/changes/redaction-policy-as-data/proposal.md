---
kind: code
depends_on: [redaction-release-safeguards, anonymisation-placeholder-id-scope]
---

# Proposal: redaction-policy-as-data

## Why

Seven rows ask that redaction policy be data the organisation owns and the engine applies on every path. Our column (`baseline/openwoo.tsv`):

| row | capability | ours today |
|---|---|---|
| 18.1 | An organisation keeps a list of terms the product must always redact, and a list it must never redact | no: `POST /api/files/{fileId}/manual-entities` adds an entity to one file; nothing makes it a standing list |
| 18.2 | A term list is scoped to one request, and does not carry into the next | no: there is no list to scope |
| 18.3 | The redaction policy is a named configuration the organisation owns, and the product applies it to a new request without an officer restating it | partial: `AnonymisationProfile` is this shape for record properties on the archival path; the document path reads three app-wide settings |
| 18.4 | The vendor's base detection policy and the organisation's own additions are separate layers, and updating the base does not discard the local layer | no: rules are shipped code (`NlPatternSet` private consts); there is no local layer |
| 18.5 | An officer defines a pattern of their own, and the product applies it as a detection rule beside the model | no: `entityRecognitionMethod: regex` selects the shipped set; no pattern field anywhere |
| 18.6 | A detection rule carries the exception ground it implies, so the ground is attached without an officer typing it | no: `EntityRelation::$bases` is set by hand through `PATCH /api/entity-relations/{id}` |
| 18.7 | A redaction profile is bound to a document type, and the next document of that type is handled the same way | partial: `AnonymisationProfile` binds to a schema; nothing binds a document redaction profile to a document type |

**Decision D2 (Ruben, 2026-10-05): filinq's guarantees move into OpenRegister's engine so every path has them**, including the always and never-redact term lists and named profiles. filinq has them for its own path only. Read on filinq `development` at f0fa284 and carried over faithfully:

- `openspec/specs/entity-publication-policies/spec.md`: the always-redact list is `publicationProhibition` (match types exactly `exact`, `normalized`, `bsn`, `kvk`; `regex` refused there; `validFrom`, `validUntil`, `active`; an in-memory rule cache, no query per entity; prohibition wins over standing consent, deterministically by lower uuid, both recorded in the audit log, with no option to invert); the never-redact list is the standing `publicationConsent` with `scope: entity`.
- `openspec/changes/anonymization-review-workbench` REQ-DDARW-004 (rules pre-apply as a default the reviewer may override, overriding a prohibition stays gated) and REQ-DDARW-005 (a rule carries optional `bases`, pre-filled into an entity's grounds only when empty; reviewer overrides win).
- `openspec/specs/batch-anonymization` "WOO entity category profiles": the default Woo profile redacts PERSON, BSN, PHONE, EMAIL, IBAN and ADDRESS and keeps ORGANIZATION, LOCATION and DATE visible.

Decision D3: the exception grounds are dossiq's list. OpenRegister stores a ground's identifier on a rule and copies it to the relation; it never holds or resolves the list.

## What changes

- **A shipped `redaction-policy` register** (`lib/Settings/redaction_policy_register.json`, imported by a repair step like `ImportDismissedPairRegister`) with three schemas, so every rule is an object: audited, organisation-scoped, versioned and readable through the object API.
  - `termRule`: `list` (`always` or `never`), `entityType`, `matchRules` (`exact`, `normalized`, `bsn`, `kvk`), `reason`, `legalAuthority`, `bases`, `validFrom`, `validUntil`, `active`, `scope` (`organisation` or `request`), `requestRef`, `layer`.
  - `detectionPattern`: `kind` (`regex` or `term`), `pattern`, `entityType`, `confidence`, `bases`, `layer`, `overrides`.
  - `redactionProfile`: `name`, `redact` and `keep` entity types, `termRuleTags`, `method` (`placeholder` or `mask`) with `maskForms`, `basesByEntityType`, `documentTypes`, `isDefault`, `layer`.
- **Two layers (18.4).** Objects shipped by OpenRegister carry `layer: base` and a stable slug; an update re-imports base objects by slug only and never writes a `local` object. An organisation switches a base rule off with a local override (`overrides: <base slug>`, `active: false`), which survives every update. The shipped Woo profile is a base object.
- **Applied at detection on every path (18.1, 18.5, 18.6).** `EntityRecognitionHandler` loads the active rules once per run into an in-memory index and, for every detection method: runs local and base patterns beside the model; searches the text for always-redact terms so a term the model missed is still found; matches every detection against both lists, prohibition first and winning; records `policyMatch` on the relation; pre-sets the decision (always: redact, never: release) as a default; copies a matched rule's `bases`, or the profile's ground for that entity type, into an empty `bases`. Overriding an always-redact match needs a supervisor or administrator and a reason. Both matching rules are written to the audit row.
- **Scoped to one request (18.2).** A `termRule` with `scope: request` applies only to a call whose `policyContext.requestRef` names that request, never to another.
- **Named profile applied without restating (18.3, 18.7).** Detection and anonymise calls accept `policyContext` (`profile`, `requestRef`, `documentType`). The profile is chosen in this order: named in the call, bound to the document type, the organisation's default, the shipped Woo profile. A document type comes from `policyContext.documentType` or from the file's metadata `documentType`; with filinq's `document-register` installed that identifier is filinq's document type.
- **filinq's lists come across.** `occ openregister:redaction-policy:import-filinq` copies filinq's `publicationProhibition` and entity-scoped `publicationConsent` objects (they are OpenRegister objects in the `filinq` register) into `termRule` objects, idempotently by source uuid. `filinq/redaction-guarantees-from-the-engine` (wave 3) runs it and retires filinq's copies.

## What does not change

- The archival `AnonymisationProfile` for record properties.
- The review gate and the verifier (`redaction-release-safeguards`); policy only pre-sets defaults the gate still asks a person to confirm when it is on.

## Dependencies and absent apps

- `redaction-release-safeguards` (wave 1) for the explicit decision model the defaults are written into.
- `anonymisation-placeholder-id-scope` (amended, wave 1) for mask forms, which a profile carries.
- Waits on `filinq/document-register` (open, 0 of 10) for 18.7's document type vocabulary. Without filinq the binding works on any identifier set as file metadata; with filinq absent the import command reports nothing to import.
- dossiq absent (D3, D12): grounds are identifiers stored and copied as text; OpenRegister's own page offers a text field and says the list is dossiq's. The consumers' pickers (filinq, opencatalogi) use their own read-only fallback.

## Wave and decision

Wave 2, size L. Implements D2 (term lists and profiles in the engine) and honours D3 (grounds are dossiq's, stored as identifiers). Closes 18.1 to 18.7.
