---
kind: code
depends_on: []
---

## Why

Three fleet apps need to prove, not just record, that a specific person consented to a specific thing at a specific moment: learniq's guardian beeldmateriaal/photo consent (round-1 finding `PA-new-7`, evidenced under BW 3:15a — the Dutch Civil Code provision on the evidentiary value of an electronic record), the AVG verwerkingsregister's Art. 6(1)(a)/Art. 7 processing consent, and any future app with a consent-shaped checkbox. Today each app that needs this either models consent as a plain boolean (learniq's `guardianConsentGiven`-shaped flag, per `po-research-2026-09-25.md`) or, in OpenRegister's own `avg-verwerkingsregister` spec, as a bespoke fourth schema scoped to processing activities (`openspec/specs/avg-verwerkingsregister/spec.md:309-340`): it records `consentDatum`/`consentMethode`/betrokkene and is immutable-by-convention ("withdrawal creates a new record, does not modify the original"), but it captures no IP address, no user agent, and no content hash of what was agreed to — the three elements a Dutch court actually weighs under BW 3:15a when an electronic consent is disputed. Neither shape is declarative or reusable: a third app cannot opt a property into the same evidentiary guarantee without re-authoring the pattern from scratch.

This duplicates exactly the shape ADR-022 (apps consume OR abstractions) exists to prevent, the same convergence pattern that produced `processing-activity-register` when three apps wrote near-identical AVG changes in one day. `PA-new-7`'s ask is one property-level evidentiary primitive that any schema can attach to any consent-shaped property, filled by the platform rather than the app, so consent evidence stops being reinvented — or under-built — per app.

## What Changes

- **Add `x-openregister-consent`**, a schema-level dialect (like `x-openregister-notifications`, `x-openregister-calculations`) that names a property as consent-shaped and declares its `purpose` (a fixed string identifying what is being consented to) and optionally `subjectProperty` (the property on the same object identifying the data subject, defaulting to the authenticated caller).
- **Add a `ConsentAnnotationValidator`**, wired into `SchemaMapper` at schema-save time exactly like `CalculationAnnotationValidator`, that rejects a malformed `x-openregister-consent` declaration (missing `purpose`, wrong property type — the property MUST be declared `type: array`) with HTTP 422.
- **Add a `ConsentEnvelopeOnSaveListener`**, subscribed to `ObjectCreatingEvent`/`ObjectUpdatingEvent` exactly like `CalculationOnSaveListener`. For each `x-openregister-consent` property:
  - On an appended array entry (a new consent action — `decision: granted|refused|withdrawn`), the listener fills the read-only evidentiary fields the caller cannot set itself: `by` (the acting user, or the configured `subjectProperty`'s resolved identity when the caller is a public/anonymous actor writing on a data subject's behalf), `timestamp` (server clock, RFC3339), `ip` (`IRequest::getRemoteAddress()`), `userAgent` (`IRequest::getHeader('User-Agent')`), and `contentHash` (`hash('sha256', …)` over `purpose` + `decision` + the caller-supplied `evidenceOf` string — e.g. the exact consent-text version shown — so the hash proves what was agreed to, not just that something was).
  - **Refuses any mutation of an existing array entry.** Comparing the incoming array against the previously persisted one (from `ObjectUpdatingEvent::getOldObject()`), any entry at an existing index whose stored value differs from the incoming value calls `$event->setErrors([...])` + `$event->stopPropagation()` — the same idiom `UniqueConstraintListener` already uses for a `refuse`-action constraint, which `MagicMapper` turns into the existing `HookStoppedException`, answered by the objects controller as HTTP 422. Append-only, matching the existing AVG "withdrawal creates a new record" convention, generalised as a mechanical rule instead of an app-level promise. A withdrawal is expressed the same way: appending a new entry with `decision: withdrawn` and its own `withdrawnAt` timestamp; it never edits the granted entry.
- Not a breaking change: a schema that declares no `x-openregister-consent` property keeps exactly today's behaviour — plain array properties round-trip unchanged.

## Capabilities

### New Capabilities
- `consent-evidence-envelope`: a declarative, append-only, evidentiary consent shape any schema can attach to an array property via `x-openregister-consent`, filled by the platform at write time (subject/purpose/decision app-supplied; by/timestamp/ip/userAgent/contentHash platform-filled) and mechanically protected against in-place mutation.

### Modified Capabilities
(none — `avg-verwerkingsregister`'s own consent-as-legal-basis requirement is a consumer this primitive could later back, not a requirement this change edits; extending that link is out of scope here and left for the app that wants it)

## Impact

- **Code**: `lib/Service/Consent/ConsentAnnotationValidator.php` (new), `lib/Listener/ConsentEnvelopeOnSaveListener.php` (new — subscribed via `lib/AppInfo/Application.php` on `ObjectCreatingEvent`/`ObjectUpdatingEvent`, reusing the existing `stopPropagation()`/`HookStoppedException` refusal idiom `UniqueConstraintListener` already uses), `lib/Db/SchemaMapper.php` (+1 validator call, mirroring `CalculationAnnotationValidator`'s wiring), `lib/Service/Consent/ConsentDeclarationException.php` (new — schema-save-time rejection, mirrors `CalculationDeclarationException`).
- **Tests**: `tests/Unit/Service/Consent/ConsentAnnotationValidatorTest.php`, `tests/Unit/Service/Consent/ConsentEnvelopeOnSaveListenerTest.php`.
- **Consumers**: learniq's `PA-new-7` (guardian beeldmateriaal consent) and portaliq's guardian-facing consent rows can declare `x-openregister-consent` on their existing consent property instead of building bespoke evidence capture; `avg-verwerkingsregister`'s own consent schema may adopt it in a future, separate change.
- **Backward compatibility**: no existing schema declares `x-openregister-consent` today, so no existing write path is affected.
