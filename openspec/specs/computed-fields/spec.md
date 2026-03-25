---
status: ready
---

# computed-fields Specification

## Purpose
Document and extend computed field capabilities using Twig expressions within schema property definitions. Computed fields MUST support string, number, date, and cross-reference functions that are evaluated server-side on read or on save. The system MUST define clear extension points for custom function registration.

**Source**: Gap identified in cross-platform analysis; three platforms implement formula/computed fields.

## ADDED Requirements

### Requirement: Schema properties MUST support a computed expression
Schema property definitions MUST accept a `computed` attribute containing a Twig expression that derives the field value from other properties.

#### Scenario: Simple string concatenation
- GIVEN a schema `personen` with properties `voornaam`, `achternaam`
- AND a computed property `volledigeNaam` with expression `{{ voornaam }} {{ achternaam }}`
- WHEN an object is created with voornaam `Jan` and achternaam `de Vries`
- THEN `volledigeNaam` MUST be computed as `Jan de Vries`

#### Scenario: Numeric calculation
- GIVEN a schema `subsidies` with properties `bedrag` and `btw_percentage`
- AND a computed property `bedrag_incl_btw` with expression `{{ bedrag * (1 + btw_percentage / 100) }}`
- WHEN an object is created with bedrag `10000` and btw_percentage `21`
- THEN `bedrag_incl_btw` MUST be computed as `12100`

#### Scenario: Date calculation
- GIVEN a schema `vergunningen` with property `ingangsdatum`
- AND a computed property `vervaldatum` with expression `{{ ingangsdatum|date_modify('+1 year')|date('Y-m-d') }}`
- WHEN ingangsdatum is `2026-03-15`
- THEN vervaldatum MUST be computed as `2027-03-15`

### Requirement: Computed fields MUST be evaluated server-side
Computed expressions MUST be evaluated on the server during read or save operations, not on the client.

#### Scenario: Evaluate on save
- GIVEN a computed field configured with `evaluateOn: save`
- WHEN an object is created or updated
- THEN the computed value MUST be calculated and stored in the database
- AND subsequent reads MUST return the stored value without re-evaluation

#### Scenario: Evaluate on read
- GIVEN a computed field configured with `evaluateOn: read`
- WHEN an object is fetched via API
- THEN the computed value MUST be calculated at read time
- AND the computed value MUST NOT be stored in the database
- AND the API response MUST include the computed value alongside stored values

### Requirement: Computed fields MUST support cross-reference lookups
Computed expressions MUST be able to reference properties of related objects via $ref references.

#### Scenario: Cross-reference lookup
- GIVEN schema `orders` with property `klant` referencing schema `klanten`
- AND a computed property `klant_naam` with expression `{{ _ref.klant.naam }}`
- WHEN an order references klant `klant-1` with naam `Gemeente Utrecht`
- THEN `klant_naam` MUST be computed as `Gemeente Utrecht`

#### Scenario: Missing reference
- GIVEN a computed field referencing `{{ _ref.klant.naam }}`
- AND the order has no klant reference (null)
- WHEN the field is evaluated
- THEN the computed value MUST be an empty string (not an error)

### Requirement: Computed fields MUST be read-only in the UI
Computed properties MUST be displayed but not editable in forms.

#### Scenario: Display computed field in form
- GIVEN a computed property `volledigeNaam`
- WHEN the user views the object edit form
- THEN `volledigeNaam` MUST be displayed as a read-only field
- AND it MUST be visually distinguished from editable fields (gray background or similar)

### Requirement: The system MUST support custom function registration
Developers MUST be able to register custom Twig functions and filters for use in computed expressions.

#### Scenario: Register a custom function
- GIVEN a developer registers a Twig function `format_postcode(code)` via the extension API
- AND a computed expression uses `{{ format_postcode(postcode) }}`
- WHEN an object has postcode `1234AB`
- THEN the function MUST be called and its return value used as the computed result

### Requirement: Computed field errors MUST be handled gracefully
Expression evaluation errors MUST NOT prevent object operations.

#### Scenario: Division by zero
- GIVEN a computed expression `{{ total / count }}`
- WHEN count is `0`
- THEN the computed value MUST be null (not an error)
- AND a warning MUST be logged: `Computed field evaluation error: division by zero`
- AND the object MUST still be saved/returned successfully

### Current Implementation Status
- **Partial foundations:**
  - Twig environment is already integrated into OpenRegister for mapping templates:
    - `MappingExtension` (`lib/Twig/MappingExtension.php`) registers custom Twig filters (`b64enc`, `b64dec`, `json_decode`, `zgw_enum`, `zgw_enum_reverse`, `zgw_extract_uuid`) and functions (`executeMapping`, `generateUuid`)
    - `MappingRuntime` (`lib/Twig/MappingRuntime.php`) provides the runtime implementations
    - `MappingRuntimeLoader` (`lib/Twig/MappingRuntimeLoader.php`) loads the runtime for Twig
    - `AuthenticationExtension` (`lib/Twig/AuthenticationExtension.php`) adds `oauthToken` function
  - Twig is used in mapping/transformation contexts (OpenConnector integration) but NOT for computed schema properties
  - Schema properties support JSON Schema definitions but have no `computed` attribute
  - `SaveObject` (`lib/Service/Object/SaveObject.php`) and `MetadataHydrationHandler` (`lib/Service/Object/SaveObject/MetadataHydrationHandler.php`) handle field processing during save operations
  - `RenderObject` (`lib/Service/Object/RenderObject.php`) handles output rendering (potential hook point for read-time evaluation)
  - `ValidationHandler` (`lib/Service/Object/ValidationHandler.php`) validates properties against schema
- **NOT implemented:**
  - `computed` attribute on schema property definitions
  - Server-side Twig expression evaluation for computed fields
  - `evaluateOn` configuration (save vs. read)
  - Cross-reference lookups via `_ref` in expressions
  - Read-only UI rendering for computed fields
  - Custom function registration API for computed expressions
  - Error handling (division by zero, null references) for computed field evaluation

### Standards & References
- **JSON Schema** — Property definitions extended with `computed` attribute
- **Twig 3.x** — Template engine for expression evaluation
- **OpenAPI 3.0** — `readOnly` property attribute for computed fields in API spec
- **JSON Schema `readOnly`** — Standard way to mark fields as not user-writable

### Specificity Assessment
- The spec is well-defined with clear scenarios for each use case.
- The Twig foundation is already in place, making implementation feasible by extending the existing Twig environment.
- Missing: how `computed` is defined in the JSON Schema property definition (custom keyword? `x-computed`?); how computed fields interact with validation (skipped during input validation?); how computed fields affect search and filtering (indexed? searchable?).
- Ambiguous: the `_ref` syntax for cross-reference lookups — how are nested references resolved? Is there a depth limit? What about circular references?
- Open questions:
  - Should computed fields be stored in the database when `evaluateOn: save` or computed on-the-fly always?
  - How do computed fields interact with import/export — are they included in exports? Ignored during imports?
  - What is the performance impact of evaluating computed fields on read for large result sets?
  - Should there be a sandbox/security model for Twig expressions to prevent abuse?

## Nextcloud Integration Analysis

**Status**: PARTIALLY IMPLEMENTED

**What Exists**: The Twig template engine is fully integrated into OpenRegister for mapping and data transformation. Custom Twig extensions are registered (`MappingExtension.php` with filters like `b64enc`, `json_decode`, `zgw_enum` and functions like `executeMapping`, `generateUuid`). `MappingRuntime.php` and `MappingRuntimeLoader.php` provide the runtime infrastructure. `AuthenticationExtension.php` adds OAuth token functions. The `SaveObject` pipeline and `MetadataHydrationHandler` process field values during save, and `RenderObject` handles output rendering -- both are natural hook points for computed field evaluation.

**Gap Analysis**: Twig is used exclusively in mapping/transformation contexts (OpenConnector integration), not as a first-class schema property type. No `computed` attribute exists on schema property definitions. There is no `evaluateOn` configuration (save vs. read), no cross-reference lookups via `_ref` syntax, no read-only UI rendering for computed fields, and no custom function registration API specifically for computed expressions. Error handling for expression evaluation (division by zero, null references) is not implemented.

**Nextcloud Core Integration Points**:
- **IJobList (Background Jobs)**: Register a `TimedJob` for batch recalculation of `evaluateOn: save` computed fields when source data changes. This avoids blocking API responses when many dependent fields need updating. Use `\OCP\BackgroundJob\IJobList::add()` to schedule recalculation jobs.
- **ICache / APCu via ICacheFactory**: Memoize frequently evaluated `evaluateOn: read` computed expressions using `\OCP\ICacheFactory::createDistributed('openregister_computed')`. Cache keys based on object ID + expression hash, with TTL matching data volatility.
- **Twig Sandbox Extension**: Use Twig's built-in `SandboxExtension` with a `SecurityPolicy` to restrict allowed tags, filters, and functions in user-defined expressions. This prevents abuse (file access, code execution) while allowing safe computation.
- **IEventDispatcher**: Listen to `ObjectUpdatedEvent` to trigger recalculation of dependent computed fields when source properties change, enabling reactive updates across related objects.

**Recommendation**: Start by adding the `computed` attribute to schema property definitions in `Schema.php` and implementing `evaluateOn: save` evaluation in `MetadataHydrationHandler`. This builds directly on the existing Twig infrastructure with minimal new code. Use the existing `MappingExtension` as the function registry for computed expressions -- extend it rather than creating a parallel system. For `evaluateOn: read`, add evaluation in `RenderObject.php` with APCu memoization via `ICacheFactory`. Cross-reference lookups (`_ref`) should resolve via `ObjectService::getObject()` with a depth limit to prevent circular evaluation. Background jobs for batch recalculation are a phase-2 concern.
