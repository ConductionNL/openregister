---
status: done
retrofit_extensions:
  - REQ-001
---

# Computed Fields

## Purpose

@e2e exclude server-side Twig expression engine — covered by PHPUnit
Computed fields enable schema properties whose values are derived automatically from expressions evaluated against object data, cross-referenced objects, and aggregation functions. This capability eliminates redundant data entry, ensures consistency of derived values (full names, totals, expiry dates), and brings spreadsheet-like formula power to OpenRegister without requiring external workflow engines for simple calculations. Computed fields use Twig expressions evaluated server-side, leveraging the existing Twig infrastructure already integrated into OpenRegister for mapping and transformation.
## Requirements
### Requirement: Schema Property Computed Attribute Definition
Schema property definitions MUST support a `computed` object attribute that defines the expression, evaluation mode, and metadata for deriving field values. The `computed` attribute MUST contain an `expression` key (Twig template string) and MAY contain `evaluateOn` (default `save`), `description`, and `dependsOn` keys. The `computed` attribute MUST be stored as part of the schema property definition in the standard JSON Schema `properties` object, using a vendor extension pattern consistent with ADR-006.

#### Scenario: Define a computed property with string concatenation
- **GIVEN** a schema `personen` with properties `voornaam` (string) and `achternaam` (string)
- **WHEN** a property `volledigeNaam` is defined with `computed.expression` set to `{{ voornaam }} {{ achternaam }}`
- **THEN** the schema MUST store the computed attribute alongside the property type definition
- **AND** the property MUST be treated as read-only by ValidationHandler during input validation

#### Scenario: Define a computed property with numeric calculation
- **GIVEN** a schema `subsidies` with properties `bedrag` (number) and `btw_percentage` (number)
- **WHEN** a property `bedrag_incl_btw` is defined with `computed.expression` set to `{{ bedrag * (1 + btw_percentage / 100) }}`
- **THEN** the schema MUST accept the expression without validation errors
- **AND** ComputedFieldHandler MUST cast the result to a numeric type via `castResult()`

#### Scenario: Define a computed property with date calculation
- **GIVEN** a schema `vergunningen` with property `ingangsdatum` (date)
- **WHEN** a property `vervaldatum` is defined with `computed.expression` set to `{{ ingangsdatum|date_modify('+1 year')|date('Y-m-d') }}`
- **THEN** the computed value MUST be evaluated using the allowed `date` and `date_modify` filters in the sandbox policy

#### Scenario: Reject a computed attribute without an expression
- **GIVEN** a schema property defines `computed: {}` with no `expression` key
- **WHEN** ComputedFieldHandler iterates schema properties
- **THEN** the property MUST be skipped (not evaluated) because `expression` is empty

#### Scenario: Computed attribute with explicit dependsOn declaration
- **GIVEN** a computed property `totaal` with `computed.dependsOn` set to `["bedrag", "korting"]`
- **WHEN** the schema is saved
- **THEN** the dependency list MUST be stored for use by circular dependency detection and cache invalidation

### Requirement: Save-Time Evaluation
Computed fields configured with `evaluateOn: save` (the default) MUST be evaluated by ComputedFieldHandler during the SaveObject pipeline, and the resulting value MUST be persisted to the database. This ensures computed values are available for search indexing, filtering, and sorting without runtime overhead.

#### Scenario: Compute and persist value on object creation
- **GIVEN** a computed field `volledigeNaam` with `evaluateOn: save`
- **WHEN** an object is created with `voornaam: "Jan"` and `achternaam: "de Vries"`
- **THEN** SaveObject MUST invoke `ComputedFieldHandler.evaluateComputedFields(data, schema, 'save')` before persistence
- **AND** the value `Jan de Vries` MUST be stored in the database
- **AND** subsequent reads MUST return the stored value without re-evaluation

#### Scenario: Recompute value on object update
- **GIVEN** an existing object with computed `volledigeNaam` = `Jan de Vries`
- **WHEN** `achternaam` is updated to `van Dijk`
- **THEN** ComputedFieldHandler MUST re-evaluate the expression during the save pipeline
- **AND** `volledigeNaam` MUST be updated to `Jan van Dijk`

#### Scenario: User-provided value for save-time computed field is overwritten
- **GIVEN** a computed field `bedrag_incl_btw` with `evaluateOn: save`
- **WHEN** the API request includes `bedrag_incl_btw: 99999` alongside `bedrag: 10000` and `btw_percentage: 21`
- **THEN** the user-provided value MUST be overwritten by the computed result `12100`

#### Scenario: Save-time computed field is indexed by Solr
- **GIVEN** a schema with Solr indexing enabled and a computed field `volledigeNaam` with `evaluateOn: save`
- **WHEN** an object is saved
- **THEN** the computed value MUST be included in the Solr document because it is persisted to the database before indexing

### Requirement: Read-Time Evaluation
Computed fields configured with `evaluateOn: read` MUST be evaluated by ComputedFieldHandler during the RenderObject pipeline. The computed value MUST NOT be stored in the database and MUST be calculated fresh on every API response. This mode is appropriate for volatile expressions such as `NOW()` or values that depend on frequently-changing referenced objects.

#### Scenario: Compute value at read time
- **GIVEN** a computed field `dagen_resterend` with expression `{{ ((vervaldatum|date('U')) - ("now"|date('U'))) / 86400 }}` and `evaluateOn: read`
- **WHEN** an object is fetched via the API
- **THEN** RenderObject MUST invoke `ComputedFieldHandler.evaluateComputedFields(data, schema, 'read')` during rendering
- **AND** the API response MUST include the freshly computed value

#### Scenario: Read-time computed field is NOT stored in the database
- **GIVEN** a computed field with `evaluateOn: read`
- **WHEN** an object is saved
- **THEN** the computed field MUST NOT appear in the persisted object data
- **AND** only when the object is rendered for API output MUST the value be calculated

#### Scenario: Read-time computed field in bulk listing
- **GIVEN** a schema with a read-time computed field and 500 objects
- **WHEN** a list endpoint returns 50 objects per page
- **THEN** ComputedFieldHandler MUST evaluate expressions for all 50 objects in the response
- **AND** total evaluation time for the page SHOULD remain under 200ms

#### Scenario: Read-time computed field is absent from search indexes
- **GIVEN** a computed field with `evaluateOn: read`
- **WHEN** objects are indexed to Solr or the database facet system
- **THEN** the read-time computed field MUST NOT be included in the index because it has no persisted value

### Requirement: On-Demand Evaluation Mode
Computed fields configured with `evaluateOn: demand` MUST only be evaluated when explicitly requested via an API query parameter (e.g., `_computed=true` or `_fields=computedFieldName`). This mode is intended for expensive computations such as cross-register aggregations.

#### Scenario: Demand-mode field excluded by default
- **GIVEN** a computed field `gemiddelde_score` with `evaluateOn: demand`
- **WHEN** an object is fetched via the API without `_computed=true`
- **THEN** the computed field MUST NOT appear in the response

#### Scenario: Demand-mode field included when requested
- **GIVEN** a computed field `gemiddelde_score` with `evaluateOn: demand`
- **WHEN** an object is fetched with query parameter `_computed=true`
- **THEN** ComputedFieldHandler MUST evaluate the expression and include it in the response

#### Scenario: Demand-mode field requested via _fields parameter
- **GIVEN** a computed field `gemiddelde_score` with `evaluateOn: demand`
- **WHEN** an object is fetched with `_fields=naam,gemiddelde_score`
- **THEN** only `naam` and the evaluated `gemiddelde_score` MUST appear in the response

### Requirement: Cross-Field References Within the Same Object
Computed expressions MUST be able to reference any property of the same object by name. All non-computed properties of the object MUST be available as Twig variables in the expression context. Computed fields MUST be evaluated in dependency order so that a computed field MAY reference another computed field that has already been evaluated.

#### Scenario: Reference multiple fields in one expression
- **GIVEN** a schema `facturen` with properties `aantal` (integer), `prijs_per_stuk` (number), and `korting` (number)
- **WHEN** a computed field `totaal` has expression `{{ (aantal * prijs_per_stuk) - korting }}`
- **THEN** all three source fields MUST be available in the Twig context
- **AND** the expression MUST evaluate correctly

#### Scenario: Computed field references another computed field
- **GIVEN** computed field `subtotaal` with expression `{{ aantal * prijs_per_stuk }}` (order 1)
- **AND** computed field `totaal` with expression `{{ subtotaal - korting }}` (order 2)
- **WHEN** the object is saved with `aantal: 5`, `prijs_per_stuk: 100`, `korting: 50`
- **THEN** `subtotaal` MUST be evaluated first, yielding `500`
- **AND** `totaal` MUST be evaluated second, yielding `450`

#### Scenario: Missing source property defaults to null
- **GIVEN** a computed expression `{{ optionele_toeslag|default(0) + bedrag }}`
- **WHEN** the object has no `optionele_toeslag` property set
- **THEN** the Twig `default` filter MUST provide `0` and the expression MUST evaluate without error

### Requirement: Cross-Object Reference Lookups
Computed expressions MUST support referencing properties of related objects via the `_ref` namespace. When a schema property holds a UUID reference to another object, ComputedFieldHandler MUST resolve that reference and make the referenced object's data available under `_ref.propertyName` in the Twig context. Resolution MUST respect the MAX_REF_DEPTH constant (currently 3) to prevent unbounded lookups.

#### Scenario: Lookup a property from a referenced object
- **GIVEN** schema `orders` with property `klant` (UUID reference to schema `klanten`)
- **AND** a computed property `klant_naam` with expression `{{ _ref.klant.naam }}`
- **WHEN** the order references a klant object with `naam: "Gemeente Utrecht"`
- **THEN** ComputedFieldHandler MUST resolve the klant UUID via MagicMapper.find()
- **AND** `klant_naam` MUST be computed as `Gemeente Utrecht`

#### Scenario: Null reference returns empty data
- **GIVEN** a computed field referencing `{{ _ref.klant.naam }}`
- **WHEN** the `klant` property is null (no reference set)
- **THEN** `_ref.klant` MUST resolve to an empty array
- **AND** the expression MUST evaluate to an empty string (not throw an error)

#### Scenario: Nested cross-reference within depth limit
- **GIVEN** an order references a klant, and the klant references an organisatie
- **AND** a computed field uses `{{ _ref.klant.organisatie_naam }}`
- **WHEN** the depth is within MAX_REF_DEPTH (3)
- **THEN** the reference chain MUST resolve successfully

#### Scenario: Cross-reference exceeding MAX_REF_DEPTH
- **GIVEN** a reference chain deeper than MAX_REF_DEPTH (3 levels)
- **WHEN** ComputedFieldHandler attempts to resolve references
- **THEN** resolution MUST stop at the depth limit
- **AND** a warning MUST be logged: `[ComputedFieldHandler] Max reference resolution depth exceeded`
- **AND** unreachable references MUST resolve to empty arrays

#### Scenario: Referenced object does not exist
- **GIVEN** a computed field references `{{ _ref.klant.naam }}`
- **AND** the klant UUID points to a deleted or non-existent object
- **WHEN** MagicMapper.find() throws DoesNotExistException
- **THEN** `_ref.klant` MUST resolve to an empty array
- **AND** the error MUST be logged at debug level

### Requirement: Aggregation Functions Across Related Objects
Computed expressions MUST support aggregation over collections of related objects. When a property references an array of UUIDs (one-to-many relation), the system MUST resolve all referenced objects and provide aggregation functions (SUM, COUNT, AVG, MIN, MAX) as Twig functions or filters.

#### Scenario: COUNT of related objects
- **GIVEN** schema `projecten` with property `taken` (array of UUID references to schema `taken`)
- **AND** a computed field `aantal_taken` with expression `{{ taken|length }}`
- **WHEN** `taken` contains 5 UUIDs
- **THEN** `aantal_taken` MUST be computed as `5`

#### Scenario: SUM of a property across related objects
- **GIVEN** a computed field `totaal_uren` with expression `{{ _ref_list.taken|map(t => t.uren)|reduce((carry, v) => carry + v, 0) }}`
- **WHEN** the referenced taken have uren values `[8, 4, 6, 2]`
- **THEN** `totaal_uren` MUST be computed as `20`

#### Scenario: AVG of a property across related objects
- **GIVEN** a computed field `gemiddelde_score` with expression `{{ _ref_list.beoordelingen|map(b => b.score)|reduce((c, v) => c + v, 0) / (_ref_list.beoordelingen|length) }}`
- **WHEN** scores are `[8, 7, 9]`
- **THEN** `gemiddelde_score` MUST be computed as `8`

#### Scenario: Empty collection returns zero for aggregation
- **GIVEN** a computed field aggregating over `_ref_list.taken`
- **WHEN** the `taken` array is empty
- **THEN** COUNT MUST return `0`
- **AND** SUM MUST return `0`
- **AND** AVG MUST return `0` (not division by zero)

### Requirement: String, Date, and Math Operations
The Twig sandbox security policy MUST allow a curated set of filters and functions for common string, date, and mathematical operations. The allowed operations MUST cover the most common use cases identified in competitive analysis (NocoDB provides 65 functions; OpenRegister targets the 80/20 set via Twig's built-in capabilities).

#### Scenario: String operations
- **GIVEN** allowed Twig filters include `upper`, `lower`, `trim`, `split`, `join`, `slice`, `first`, `last`, `replace`, `format`, `length`
- **WHEN** a computed expression uses `{{ voornaam|upper }}`
- **THEN** the expression MUST evaluate successfully within the sandbox

#### Scenario: Date operations
- **GIVEN** allowed Twig filters include `date`, `date_modify`
- **WHEN** a computed expression uses `{{ ingangsdatum|date_modify('+6 months')|date('Y-m-d') }}`
- **THEN** the date arithmetic MUST be performed correctly

#### Scenario: Math operations
- **GIVEN** allowed Twig functions include `max`, `min`, `range`
- **AND** allowed filters include `abs`, `round`, `number_format`
- **WHEN** a computed expression uses `{{ (bedrag * 1.21)|round(2) }}`
- **THEN** the result MUST be rounded to 2 decimal places

#### Scenario: Conditional logic using Twig ternary
- **GIVEN** a computed expression `{{ status == 'actief' ? 'Ja' : 'Nee' }}`
- **WHEN** `status` is `actief`
- **THEN** the result MUST be `Ja`

#### Scenario: Disallowed filter is blocked by sandbox
- **GIVEN** a computed expression attempts to use a filter not in the security policy (e.g., `{{ data|raw }}`)
- **WHEN** the expression is evaluated
- **THEN** the Twig SandboxExtension MUST throw a SecurityError
- **AND** ComputedFieldHandler MUST catch the error, log a warning, and return null

### Requirement: Error Handling for Invalid Expressions
Expression evaluation errors MUST NOT prevent object save or read operations. ComputedFieldHandler MUST catch all Throwable exceptions during evaluation, log a structured warning, and return null for the computed field. The object MUST still be saved or returned successfully with the computed field set to null.

#### Scenario: Division by zero
- **GIVEN** a computed expression `{{ total / count }}`
- **WHEN** `count` is `0`
- **THEN** the computed value MUST be null
- **AND** a warning MUST be logged with context including `propertyName`, `expression`, and the error message
- **AND** the object MUST still be saved/returned successfully

#### Scenario: Reference to non-existent property
- **GIVEN** a computed expression `{{ nonExistentField * 2 }}`
- **WHEN** `nonExistentField` is not present in the object data
- **THEN** Twig MUST treat it as null
- **AND** the computed value MUST be null or an empty string

#### Scenario: Syntax error in Twig expression
- **GIVEN** a computed expression `{{ bedrag * }}`
- **WHEN** the expression is compiled by Twig
- **THEN** a Twig SyntaxError MUST be caught
- **AND** the computed value MUST be null
- **AND** a warning MUST be logged with the syntax error details

#### Scenario: Type mismatch in expression
- **GIVEN** a computed expression `{{ naam * 2 }}` where `naam` is a string
- **WHEN** the expression is evaluated
- **THEN** Twig MUST handle the type mismatch
- **AND** ComputedFieldHandler MUST return null and log the error

#### Scenario: Error in one computed field does not affect others
- **GIVEN** a schema with computed fields `a` (valid expression) and `b` (invalid expression)
- **WHEN** both fields are evaluated during save
- **THEN** field `a` MUST compute successfully
- **AND** field `b` MUST be null due to the error
- **AND** the object MUST still be saved with `a`'s computed value and `b` as null

### Requirement: Circular Dependency Detection
The system MUST detect circular dependencies between computed fields before evaluation and MUST refuse to evaluate fields involved in cycles. A computed field that depends on itself (directly or transitively) MUST produce a null value and a logged error.

#### Scenario: Direct self-reference
- **GIVEN** a computed field `a` with expression `{{ a + 1 }}`
- **WHEN** ComputedFieldHandler evaluates the field
- **THEN** the field MUST NOT enter an infinite loop
- **AND** the value MUST be null
- **AND** a warning MUST be logged: circular dependency detected

#### Scenario: Indirect circular reference (A depends on B, B depends on A)
- **GIVEN** computed field `a` with expression `{{ b * 2 }}` and computed field `b` with expression `{{ a + 1 }}`
- **WHEN** the evaluation order is determined
- **THEN** the system MUST detect the cycle
- **AND** both fields MUST evaluate to null
- **AND** a warning MUST be logged identifying the cycle

#### Scenario: Valid dependency chain is not flagged
- **GIVEN** computed field `subtotaal` depends on `aantal` and `prijs`, and `totaal` depends on `subtotaal`
- **WHEN** dependency analysis runs
- **THEN** no circular dependency MUST be detected
- **AND** evaluation MUST proceed in topological order: `subtotaal` first, then `totaal`

### Requirement: Performance and Caching
Computed field evaluation MUST NOT significantly degrade API response times. For `evaluateOn: read` fields, the system SHOULD use Nextcloud's ICacheFactory to memoize computed values based on object data hash. For `evaluateOn: save` fields, no runtime evaluation cost exists since values are pre-computed. Template compilation MUST be cached within the request lifecycle to avoid redundant Twig parsing.

#### Scenario: Twig template compilation caching within request
- **GIVEN** a schema with 3 computed fields sharing similar expressions
- **WHEN** ComputedFieldHandler evaluates all 3 fields for one object
- **THEN** each unique expression MUST be compiled once (keyed by `md5(expression)`)
- **AND** subsequent evaluations of the same expression MUST reuse the compiled template

#### Scenario: APCu memoization for read-time computed fields
- **GIVEN** a computed field with `evaluateOn: read` and a deterministic expression
- **WHEN** the same object is fetched twice within the cache TTL
- **THEN** the second fetch SHOULD return the memoized value from ICacheFactory without re-evaluation
- **AND** the cache key MUST include the object UUID and data hash to invalidate on changes

#### Scenario: Bulk evaluation performance target
- **GIVEN** a list endpoint returning 100 objects, each with 3 read-time computed fields
- **WHEN** ComputedFieldHandler evaluates all 300 expressions
- **THEN** total evaluation time SHOULD remain under 500ms for simple expressions (concatenation, arithmetic)

#### Scenario: Cross-reference resolution is the performance bottleneck
- **GIVEN** a computed field that uses `_ref` to look up a related object
- **WHEN** 50 objects each reference a different klant
- **THEN** ComputedFieldHandler MUST issue at most 50 database queries (one per unique reference)
- **AND** the system SHOULD batch or cache reference lookups within a single request

### Requirement: Computed Fields as Read-Only in the API
Computed properties MUST be exposed in API responses as regular fields but MUST be marked as `readOnly` in the OpenAPI specification. Any user-provided values for `evaluateOn: save` computed fields MUST be silently overwritten by the computed result. For `evaluateOn: read` fields, user-provided values MUST be ignored entirely since they are not persisted.

#### Scenario: Computed field appears in API response
- **GIVEN** a computed field `volledigeNaam` with `evaluateOn: save`
- **WHEN** an object is fetched via `GET /api/objects/{register}/{schema}/{id}`
- **THEN** the response MUST include `volledigeNaam` with its computed value
- **AND** the OpenAPI schema MUST declare `volledigeNaam` as `readOnly: true`

#### Scenario: Computed field in list response
- **GIVEN** a schema with computed fields
- **WHEN** objects are listed via `GET /api/objects/{register}/{schema}`
- **THEN** all computed fields (save-time and read-time) MUST appear in each object's data

#### Scenario: ValidationHandler skips computed fields during input validation
- **GIVEN** a computed field `bedrag_incl_btw`
- **WHEN** a POST or PUT request does not include `bedrag_incl_btw`
- **THEN** ValidationHandler MUST NOT flag it as a missing required field
- **AND** the computed value MUST be populated by ComputedFieldHandler

### Requirement: Computed Fields in the UI
Computed properties MUST be displayed as read-only fields in the object edit form. They MUST be visually distinguished from editable fields to prevent user confusion. The UI MUST show the current computed value and update it after save operations.

#### Scenario: Display computed field in edit form
- **GIVEN** a computed property `volledigeNaam`
- **WHEN** the user views the object edit form
- **THEN** `volledigeNaam` MUST be displayed as a read-only field with visual distinction (e.g., gray background, lock icon)
- **AND** the field MUST NOT be editable

#### Scenario: Computed field updates after save
- **GIVEN** the user changes `achternaam` from `de Vries` to `van Dijk` in the edit form
- **WHEN** the user saves the object
- **THEN** the response MUST include the recomputed `volledigeNaam: "Jan van Dijk"`
- **AND** the UI MUST display the updated value

#### Scenario: Computed field tooltip shows expression
- **GIVEN** a computed property with `computed.description: "Voornaam + achternaam"`
- **WHEN** the user hovers over the computed field
- **THEN** a tooltip SHOULD display the description explaining how the value is derived

### Requirement: Custom Twig Function Registration
Developers MUST be able to register custom Twig functions and filters for use in computed expressions via the existing MappingExtension infrastructure. Custom functions MUST be added to the sandbox security policy's allowed list. The system MUST NOT require a separate extension registry for computed fields; it MUST reuse the MappingExtension that already provides filters like `b64enc`, `json_decode`, `zgw_enum` and functions like `executeMapping`, `generateUuid`.

#### Scenario: Register a custom filter via MappingExtension
- **GIVEN** a developer adds a new filter `format_postcode` to MappingExtension
- **AND** the filter is added to the sandbox SecurityPolicy's allowed filters list in ComputedFieldHandler
- **WHEN** a computed expression uses `{{ postcode|format_postcode }}`
- **THEN** the custom filter MUST be invoked and its return value used as the computed result

#### Scenario: Custom function not in sandbox policy is blocked
- **GIVEN** a Twig function `dangerousFunction` is registered in MappingExtension but NOT added to the sandbox policy
- **WHEN** a computed expression uses `{{ dangerousFunction() }}`
- **THEN** the sandbox MUST block execution
- **AND** a SecurityError MUST be caught and logged

#### Scenario: Built-in mapping functions available in computed context
- **GIVEN** the existing `generateUuid` function is in the sandbox allowed list
- **WHEN** a computed expression uses `{{ generateUuid() }}`
- **THEN** the function MUST generate and return a valid UUID

### Requirement: Migration When Formula Changes
When a computed field's expression is modified on a schema, all existing objects with `evaluateOn: save` MUST be recalculated. The system MUST support batch recalculation via a Nextcloud background job (IJobList) to avoid blocking schema update requests. For `evaluateOn: read` fields, no migration is needed since values are computed fresh on every read.

#### Scenario: Expression change triggers batch recalculation job
- **GIVEN** a schema with 10,000 objects and a save-time computed field `volledigeNaam`
- **WHEN** an admin changes the expression from `{{ voornaam }} {{ achternaam }}` to `{{ achternaam }}, {{ voornaam }}`
- **THEN** the schema update MUST succeed immediately
- **AND** a Nextcloud QueuedJob MUST be enqueued to recalculate `volledigeNaam` for all 10,000 objects
- **AND** the job MUST process objects in batches to avoid memory exhaustion

#### Scenario: New computed field added to existing schema
- **GIVEN** a schema with 500 existing objects
- **WHEN** a new computed field `initialen` with `evaluateOn: save` is added
- **THEN** a background job MUST compute `initialen` for all 500 existing objects
- **AND** objects fetched before the job completes MUST show null for `initialen`

#### Scenario: Computed field removed from schema
- **GIVEN** a schema with a computed field `volledigeNaam` stored on 1,000 objects
- **WHEN** the `computed` attribute is removed from the property definition
- **THEN** existing stored values MUST remain in the object data (no destructive cleanup)
- **AND** the field MUST become a regular editable field

### Requirement: Audit Trail for Computed Values
Changes to computed field values MUST be tracked in the audit trail just like manually-entered values. The audit trail MUST record the previous and new computed values, and MUST indicate that the change was system-generated (by the computed field engine) rather than user-initiated.

#### Scenario: Computed value change recorded in audit trail
- **GIVEN** an object with computed `volledigeNaam: "Jan de Vries"`
- **WHEN** `achternaam` is updated to `van Dijk`, causing `volledigeNaam` to recompute to `"Jan van Dijk"`
- **THEN** the audit trail entry MUST include the change from `"Jan de Vries"` to `"Jan van Dijk"` for `volledigeNaam`
- **AND** the change source MUST be marked as `computed` (not `user`)

#### Scenario: Batch recalculation audit trail
- **GIVEN** a formula change triggers batch recalculation of 100 objects
- **WHEN** the background job processes each object
- **THEN** each object MUST receive an audit trail entry for the computed field change
- **AND** the audit trail MUST reference the schema change that triggered the recalculation

#### Scenario: Read-time computed fields are NOT audited
- **GIVEN** a computed field with `evaluateOn: read`
- **WHEN** the computed value changes because source data changed
- **THEN** no audit trail entry MUST be created for the read-time computed field (since it is never persisted)

### Requirement: Import and Export Behavior
During data import, computed field values in the import payload MUST be ignored for `evaluateOn: save` fields (they will be recomputed). During export, computed field values MUST be included in the exported data with a metadata indicator that they are computed.

#### Scenario: Import ignores computed field values
- **GIVEN** a CSV import contains a column `volledigeNaam` matching a computed field
- **WHEN** ImportService processes the row
- **THEN** the imported value for `volledigeNaam` MUST be discarded
- **AND** ComputedFieldHandler MUST compute the value from `voornaam` and `achternaam`

#### Scenario: Export includes computed field values
- **GIVEN** a schema with computed field `bedrag_incl_btw` with `evaluateOn: save`
- **WHEN** objects are exported via the API
- **THEN** the export MUST include `bedrag_incl_btw` with its computed value
- **AND** export metadata SHOULD indicate which fields are computed

#### Scenario: Import with missing source fields for computed expression
- **GIVEN** a computed field depends on `voornaam` and `achternaam`
- **WHEN** an import row has `voornaam: "Piet"` but no `achternaam`
- **THEN** the computed field MUST evaluate with `achternaam` as null/empty
- **AND** `volledigeNaam` MUST be computed as `Piet ` (trailing space from expression)

### Requirement: Interaction with Schema Hooks
Computed field evaluation MUST occur BEFORE schema hooks fire on `creating` and `updating` events. This ensures that hook workflows receive the fully-computed object data. Schema hooks (as defined in the schema-hooks spec) MAY further modify computed field values via their `modified` response status.

#### Scenario: Hook receives computed values
- **GIVEN** a schema with a save-time computed field `volledigeNaam` and a sync hook on `creating`
- **WHEN** an object is created
- **THEN** ComputedFieldHandler MUST evaluate `volledigeNaam` BEFORE HookExecutor dispatches the `creating` event
- **AND** the CloudEvent payload's `data.object` MUST include the computed `volledigeNaam` value

#### Scenario: Hook modifies a computed value
- **GIVEN** a sync hook on `creating` returns `{"status": "modified", "data": {"volledigeNaam": "Dr. Jan de Vries"}}`
- **WHEN** the hook response is processed
- **THEN** the modified value MUST override the computed value
- **AND** the object MUST be saved with `volledigeNaam: "Dr. Jan de Vries"`

#### Scenario: Async hook on created event receives computed values
- **GIVEN** an async hook on the `created` event
- **WHEN** the object is saved with computed fields
- **THEN** the CloudEvent payload MUST include all computed field values as they were saved

### REQ-001: The system SHALL provide an OCC command that re-evaluates and persists every materialised calculation declared by a (register, schema) pair

The system SHALL provide an OCC command that re-evaluates and persists every materialised calculation declared by a (register, schema) pair.

> Added by retrofit-2026-05-24-2b-command-repair-middleware (archived).

`occ openregister:rematerialise-calculations <register> <schema> [--dry-run]` iterates every object in the (register, schema) magic table, re-evaluates each materialised calculation declared in the schema's `configuration.x-openregister-calculations` map, and persists the new value when the result differs from the stored value. A calculation is "materialised" when its declaration in the calculations map satisfies `materialise === true`; non-materialised entries are skipped.

The command is implemented in `OCA\OpenRegister\Command\RematerialiseCalculationsCommand`. It accepts two required arguments (`register` and `schema`, each resolvable by slug, uuid, or id) and one optional flag (`--dry-run`). On invocation it:
1. Resolves register and schema via `RegisterMapper::find()` / `SchemaMapper::find()` with `_multitenancy: false`; lookup failures emit `<error>` to stderr and return `Command::FAILURE`.
2. Reads the calculations map via `getCalculations(schema)` (returns `$schema->getConfiguration()['x-openregister-calculations']` or null). Null or empty map → writes `<comment>Schema declares no x-openregister-calculations — nothing to do.</comment>` and exits `Command::SUCCESS`.
3. Filters the map to materialised names. Empty list → writes `<comment>No materialised calculations declared — nothing to do.</comment>` and exits `Command::SUCCESS`.
4. Pulls all entities (capped at 100,000 per run) via `MagicMapper::findAllInRegisterSchemaTable(register, schema, limit: 100000)`.
5. For each entity, builds an evaluation payload by injecting the synthetic `@self` metadata block (`id`, `uuid`, `register`, `schema`, `owner`, `created`, `updated`) into the object data via `withSelf()`. Created/updated are formatted as `DateTimeInterface::ATOM` strings (null when absent).
6. For each materialised calculation: calls `CalculationEvaluator::evaluate(payload, expression)`. `DateTimeInterface` results are normalised to ATOM strings. The stored value at `$data[$name]` is compared against the new value using `!==`; a difference marks the entity as `changed`. Per-calculation evaluation errors are counted in `$failed`, written as `<error>! <name> on <uuid>: <message></error>`, and do not abort the iteration.
7. When `--dry-run` is absent and the entity is changed, the new data is persisted via `ObjectService::saveObject(object: $data, register: $entity->getRegister(), schema: $entity->getSchema(), uuid: $entity->getUuid())`. Save failures are written as `<error>save failed on <uuid>: <message></error>` and counted in `$failed`.

The final line reports `<info>Touched <touched>, unchanged <unchanged>, failed <failed></info>`. Exit status is `Command::FAILURE` when `$failed > 0`, else `Command::SUCCESS`.

#### Scenario: Re-materialise a changed expression across the schema
- **GIVEN** a schema with `x-openregister-calculations: { totalIncl: { materialise: true, expression: "<expr-v2>" } }` whose expression was just changed from v1 to v2
- **AND** 500 objects exist in the register × schema table, all carrying the v1-evaluated value of `totalIncl`
- **WHEN** the admin runs `occ openregister:rematerialise-calculations <register> <schema>`
- **THEN** the command loads all 500 entities, evaluates v2 with the `@self`-augmented payload, and saves each entity via `ObjectService::saveObject()` where the new value differs from the stored value
- **AND** the final line reports `Touched 500, unchanged 0, failed 0`
- **AND** the command exits `Command::SUCCESS` (0)

#### Scenario: Dry-run reports diffs without persisting
- **GIVEN** 100 entities where v2 evaluation produces a value different from the stored v1 value
- **WHEN** the admin runs `occ openregister:rematerialise-calculations <register> <schema> --dry-run`
- **THEN** every entity is evaluated and counted as `touched`
- **AND** `ObjectService::saveObject()` is NEVER called
- **AND** the final line reports `Touched 100, unchanged 0, failed 0`

#### Scenario: DateTime calculation result is normalised to ATOM
- **GIVEN** a materialised calculation whose expression returns a `\DateTime` instance (e.g. `vervaldatum = ingangsdatum + 1 year`)
- **WHEN** the command evaluates it for a given entity
- **THEN** the `\DateTime` result is converted via `->format(DateTimeInterface::ATOM)` before comparison and storage
- **AND** the persisted value is the ATOM string, not the object

#### Scenario: Evaluation failure on a single entity does not abort the batch
- **GIVEN** a calculation whose expression throws on entity `<uuid-bad>`
- **WHEN** the command evaluates that entity
- **THEN** `! <name> on <uuid-bad>: <message>` is written to stderr
- **AND** `$failed` is incremented
- **AND** the loop continues with the next entity
- **AND** the final command exits `Command::FAILURE` (because `$failed > 0`)

### Requirement: Save-Time Materialisation of Declared Calculations

When a schema declares an `x-openregister-calculations` configuration block, the system MUST materialise each calculation marked `materialise: true` into the object payload before the object is persisted, on both create and update. Materialisation MUST run during the `ObjectCreatingEvent` / `ObjectUpdatingEvent` (pre-persist) phase via `CalculationOnSaveListener`. The listener MUST iterate calculations in declaration order (a later calculation MAY reference an earlier one; the validator's cycle check guarantees the graph is acyclic), evaluate each expression through `CalculationEvaluator`, and write the serialised result back into the object data. The listener MUST NOT abort the save when an individual calculation fails.

#### Scenario: Materialise a calculation into the payload on create
- **GIVEN** a schema whose configuration declares `x-openregister-calculations` with an entry `total` having `materialise: true` and an expression over other fields
- **WHEN** an object is created and `ObjectCreatingEvent` fires
- **THEN** `CalculationOnSaveListener::handle()` MUST invoke `process()` with the new object
- **AND** the evaluated value MUST be written into the object data under key `total`
- **AND** the materialised value MUST be present in the persisted object

#### Scenario: Re-materialise on update
- **GIVEN** an existing object with a materialised calculation `total`
- **WHEN** the object is updated and `ObjectUpdatingEvent` fires
- **THEN** `CalculationOnSaveListener::handle()` MUST invoke `process()` with the new object state (`getNewObject()`)
- **AND** `total` MUST be recomputed from the updated source data

#### Scenario: Only `materialise: true` entries are written
- **GIVEN** a calculations block containing one entry with `materialise: true` and one with `materialise` unset or false
- **WHEN** the object is saved
- **THEN** only the `materialise: true` entry MUST be written into the payload
- **AND** the non-materialised entry MUST be skipped

#### Scenario: Synthetic `@self` metadata is available during evaluation but stripped before persist
- **GIVEN** a calculation expression that references `@self.created` or `@self.uuid`
- **WHEN** `process()` builds the evaluation context
- **THEN** it MUST inject a `@self` array containing `id`, `uuid`, `register`, `schema`, `owner`, and ISO-8601 `created` / `updated` (null when the entity timestamp is null)
- **AND** the expression MUST resolve `@self.*` references against that block
- **AND** the `@self` key MUST be removed from the data before the payload is persisted (it is a runtime aid, not user data)

#### Scenario: No-op save does not rewrite the payload
- **GIVEN** a materialised calculation whose recomputed value equals the value already stored
- **WHEN** `process()` runs
- **THEN** the listener MUST NOT call `setObject()` (the payload is only re-set when at least one materialised value actually changed)

#### Scenario: Per-calculation evaluation error is logged and skipped
- **GIVEN** one calculation whose expression raises an `EvaluationException`
- **WHEN** `process()` evaluates the calculations in order
- **THEN** the failing calculation MUST be skipped
- **AND** a WARNING MUST be logged via `LoggerInterface` including the calculation name, the object UUID, and the error message
- **AND** the remaining calculations MUST still be evaluated and the object MUST still be saved successfully

#### Scenario: DateTime results are serialised to ATOM
- **GIVEN** a calculation that returns a `DateTimeInterface` value
- **WHEN** the result is written into the payload
- **THEN** `serialise()` MUST format it as an ATOM (`DATE_ATOM`) string
- **AND** non-DateTime values MUST be stored unchanged

#### Scenario: Schema without a calculations block is a no-op
- **GIVEN** a schema whose configuration does not contain `x-openregister-calculations` (or contains a non-array value)
- **WHEN** an object of that schema is saved
- **THEN** `getCalculations()` MUST return null and `process()` MUST return without modifying the payload

#### Notes
- `loadSchema()` resolves the object's schema via `SchemaMapper::find($ref, _multitenancy: false)`; an unresolvable or empty schema reference yields a null schema and the listener returns early. The `_multitenancy: false` flag is a system-level lookup (the listener is not user-scoped) — see the change Notes for the multitenancy-boundary follow-up.
- This listener consumes the JSON-AST `x-openregister-calculations` annotation (archived change `2026-04-29-calculations-annotation`), which is the declarative sibling of the `computed.expression` form covered by the main spec's "Save-Time Evaluation" requirement. Both materialise derived values at save time.

### Requirement: JSON-AST calculation expressions MUST be evaluated by a pure-function evaluator

OpenRegister MUST provide a second derivation engine, distinct from the Twig-based
`ComputedFieldHandler`, that evaluates calculations expressed as a single-key JSON
expression AST. `CalculationEvaluator::evaluate(array $object, mixed $expression)`
MUST accept either a bare scalar literal (resolved through the shared
`PlaceholderResolver`) or a single-key array of the form `{ "<op>": <args> }`, and MUST
dispatch on the operator key. The recognised v1 vocabulary is: `prop`, `lit`, `concat`,
`if`, `not`, `and`, `or`, the arithmetic operators `+ - * / %`, the comparison operators
`eq ne lt lte gt gte`, and the date operators `now`, `diffDays`, `formatDate`, `dateDiff`.
The evaluator MUST be side-effect-free: no I/O, no database access. Any malformed
expression, unknown operator, non-numeric arithmetic operand, zero divisor, or unknown
`dateDiff` unit MUST raise an `EvaluationException` rather than returning a silent default.

#### Scenario: Single-key dispatch on a known operator
- **GIVEN** the expression `{ "+": [{ "prop": "aantal" }, 1] }` and an object `{ "aantal": 4 }`
- **WHEN** `evaluate()` is called
- **THEN** the result MUST be `5`

#### Scenario: Bare scalar resolves through the placeholder resolver
- **GIVEN** the expression is the string `"$now"` (a placeholder, not an array)
- **WHEN** `evaluate()` is called
- **THEN** the value MUST be resolved by the shared `PlaceholderResolver` and returned as-is for non-placeholder scalars

#### Scenario: Dotted-path and @self property resolution
- **GIVEN** an object whose `@self` metadata was injected by the on-save listener
- **WHEN** the expression `{ "prop": "@self.created" }` is evaluated
- **THEN** the value at the dotted path MUST be returned
- **AND** a path that does not exist MUST resolve to null rather than raising

#### Scenario: Expression with more than one top-level key is rejected
- **GIVEN** the expression `{ "+": [1, 2], "-": [3] }` (two keys)
- **WHEN** `evaluate()` is called
- **THEN** an `EvaluationException` MUST be raised stating the expression must be a single-key object

#### Scenario: Arithmetic guards reject non-numeric and zero-divisor operands
- **GIVEN** the expression `{ "/": [{ "prop": "a" }, { "prop": "b" }] }`
- **WHEN** `b` resolves to `0`
- **THEN** an `EvaluationException` MUST be raised requiring non-zero numeric operands
- **AND** when any operand of `+ - * %` is non-numeric an `EvaluationException` MUST be raised

#### Scenario: Comparison normalises ISO-date operands before ordering
- **GIVEN** the expression `{ "lt": [{ "prop": "start" }, { "prop": "end" }] }` with ISO-8601 date strings
- **WHEN** `evaluate()` is called
- **THEN** both operands MUST be coerced to integer timestamps before the `<` comparison
- **AND** an ordering comparison against a null operand MUST yield false

#### Scenario: dateDiff computes a signed integer difference in the requested unit
- **GIVEN** the expression `{ "dateDiff": { "from": "now", "to": { "prop": "@self.dueDate" }, "unit": "days" } }`
- **WHEN** `to` is after `from`
- **THEN** the result MUST be a positive integer day count
- **AND** when `to` is before `from` the result MUST be negative
- **AND** an unsupported `unit` MUST raise an `EvaluationException`
- **AND** an unparseable `from` or `to` MUST yield null

### Requirement: Calculation annotations MUST be validated at schema-save time

`CalculationAnnotationValidator::validate(array $schema)` MUST validate the
`x-openregister-calculations` annotation before a schema is persisted and MUST return a
list of `{code, message}` errors (empty list when the annotation is absent or valid).
Each calculation declaration MUST be an object with a `type` drawn from
`[string, integer, number, boolean, date]` and a non-empty `expression`. Every `prop`
reference inside an expression MUST resolve either to a property declared on the schema,
to a sibling calculation declared in the same annotation, or to an allowlisted
`@self.<field>` system field (`id`, `uuid`, `register`, `schema`, `owner`, `created`,
`updated`). Every operator key MUST be in the v1 vocabulary. The validator MUST detect
cycles in the calc-to-calc dependency graph and report them.

#### Scenario: Absent annotation produces no errors
- **GIVEN** a schema with no `x-openregister-calculations` key
- **WHEN** `validate()` is called
- **THEN** the result MUST be an empty error list

#### Scenario: Empty annotation is rejected
- **GIVEN** a schema with `x-openregister-calculations` present but empty
- **WHEN** `validate()` is called
- **THEN** the result MUST include an error with code `calculations-empty`

#### Scenario: Bad type or missing expression is reported per calculation
- **GIVEN** a calculation declaring `type: "object"` (not in the allowed set) or omitting `expression`
- **WHEN** `validate()` is called
- **THEN** an error with code `calculation-bad-type` or `calculation-no-expression` MUST be returned for that calculation

#### Scenario: Unknown property reference is reported
- **GIVEN** a calculation whose expression references `{ "prop": "doesNotExist" }`
- **WHEN** `validate()` is called
- **THEN** an error with code `calculation-prop-unknown` MUST be returned
- **AND** a reference to a sibling calculation name MUST be accepted and recorded as a dependency edge

#### Scenario: Unknown @self field is reported
- **GIVEN** a calculation referencing `{ "prop": "@self.secret" }`
- **WHEN** `validate()` is called
- **THEN** an error with code `calculation-self-unknown` MUST be returned listing the allowed system fields

#### Scenario: Calculation cycle is detected
- **GIVEN** calculation `a` referencing `b` and calculation `b` referencing `a`
- **WHEN** `validate()` runs DFS-colouring cycle detection over the dependency graph
- **THEN** an error with code `calculation-cycle` MUST be returned naming the cycle path

#### Scenario: dateDiff argument dict is validated
- **GIVEN** a calculation using `dateDiff` without all of `from`, `to`, `unit`
- **WHEN** `validate()` is called
- **THEN** an error with code `calculation-dateDiff-missing-keys` MUST be returned
- **AND** a `dateDiff` whose `unit` is a literal string outside the supported list MUST report `calculation-dateDiff-invalid-unit`

### Requirement: Static Identifier Extraction From Twig Expression Source
The system MUST extract candidate variable identifiers from a Twig expression by regex-scanning the source text of every `{{ ... }}` and `{% ... %}` block, without parsing the expression through a Twig AST or sandbox environment. The extractor MUST collect only the top-level word of dotted or array references (`foo`, `foo.bar`, `foo[0]` all yield `foo`). The extractor MUST skip a curated reserved-word set covering Twig keywords (`and`, `or`, `not`, `in`, `is`, `as`, `with`, `if`, `else`, `elseif`, `endif`, `for`, `endfor`, `set`, `do`, `block`, `endblock`), literals (`true`, `false`, `null`), and built-in helpers used inside expressions (`date`, `now`, `min`, `max`, `range`, `random`, `length`, `count`). The extractor MUST strip the cross-object reference prefix `_ref` from the result because `_ref.*` references are foreign-object lookups, not in-schema dependencies.

#### Scenario: Identifier extracted from a simple expression
- **GIVEN** the expression `{{ voornaam }} {{ achternaam }}`
- **WHEN** `extractTwigVariables` runs
- **THEN** the result MUST contain `voornaam` and `achternaam`
- **AND** the result MUST NOT contain any Twig keyword

#### Scenario: Top-level identifier of a dotted reference
- **GIVEN** the expression `{{ klant.naam }}`
- **WHEN** `extractTwigVariables` runs
- **THEN** the result MUST contain `klant`
- **AND** the result MUST NOT contain `naam` (it is a sub-property, not a top-level identifier)

#### Scenario: Cross-object reference prefix is stripped
- **GIVEN** the expression `{{ _ref.klant.naam }}`
- **WHEN** `extractTwigVariables` runs
- **THEN** the result MUST NOT contain `_ref`
- **AND** the identifier `_ref` MUST be filtered out because cross-object references are not in-schema dependencies

#### Scenario: Plain-text content outside Twig blocks is ignored
- **GIVEN** the expression `Hello {{ name }} world`
- **WHEN** `extractTwigVariables` runs
- **THEN** the result MUST contain `name`
- **AND** the result MUST NOT contain `Hello` or `world` (plain text outside `{{ }}` / `{% %}` is not scanned)

#### Scenario: Empty expression yields empty result
- **GIVEN** an expression with no `{{ }}` or `{% %}` blocks
- **WHEN** `extractTwigVariables` runs
- **THEN** the result MUST be an empty array

### Requirement: Computed-Only Dependency Graph Construction
The system MUST build the cycle-detection dependency graph using only edges between computed properties. Before extraction, the system MUST collect the set of property names whose definition contains a `computed` array attribute. For each computed property, after extracting candidate identifiers from its expression, the system MUST filter the identifiers down to those also in the computed-property set; references to non-computed properties MUST be discarded because non-computed inputs are inert leaves that cannot close a cycle. The resulting graph MUST be an adjacency list keyed by computed property name with deduplicated edge targets.

#### Scenario: Non-computed property reference is filtered from graph
- **GIVEN** computed field `totaal` with expression `{{ aantal * prijs - korting }}`
- **AND** `aantal`, `prijs`, `korting` are non-computed (plain) properties
- **WHEN** the dependency graph is built
- **THEN** the graph entry for `totaal` MUST be an empty edge list
- **AND** no graph entry MUST be created for `aantal`, `prijs`, or `korting` (they are not computed)

#### Scenario: Computed-to-computed reference becomes a graph edge
- **GIVEN** computed field `subtotaal` (expression `{{ aantal * prijs }}`)
- **AND** computed field `totaal` (expression `{{ subtotaal - korting }}`)
- **WHEN** the dependency graph is built
- **THEN** the graph MUST contain an edge `totaal -> subtotaal`

#### Scenario: Schema with no computed properties yields an empty graph
- **GIVEN** a schema whose `properties` array contains no entry with a `computed` attribute
- **WHEN** `detectCircularDependencies` runs
- **THEN** the function MUST return an empty cycle list immediately without building the graph

#### Scenario: Computed property with empty expression has empty edge list
- **GIVEN** a computed property whose `computed.expression` is missing or the empty string
- **WHEN** the dependency graph is built
- **THEN** the graph entry for that property MUST be an empty edge list (the property is in the graph as a node but has no outgoing edges)

### Requirement: DFS-Based Back-Edge Cycle Detection
The system MUST detect cycles in the computed-field dependency graph by depth-first traversal. The traversal MUST maintain an explicit stack of nodes on the current path. When the traversal encounters a node that is already on the stack, that node is a back-edge target and the path from that node back to itself (the slice of the stack from the matched index plus the duplicated closing node) MUST be recorded as a cycle. The traversal MUST also maintain a globally-visited set so that subtrees already explored from another starting node are not re-walked. The traversal MUST be invoked once per node in the graph so cycles reachable only from non-root entry points are still detected.

#### Scenario: Self-loop is detected as a length-1 cycle
- **GIVEN** computed field `a` with expression `{{ a + 1 }}` (so `a -> a` is an edge)
- **WHEN** `dfsForCycles` is called for node `a`
- **THEN** the cycle `[a, a]` (start `a`, back-edge to `a`) MUST be recorded

#### Scenario: Two-node cycle is detected from either starting node
- **GIVEN** edges `a -> b` and `b -> a`
- **WHEN** DFS runs from node `a`
- **THEN** the cycle `[a, b, a]` MUST be recorded

#### Scenario: Shared subtree not re-walked
- **GIVEN** a graph with nodes `a`, `b`, `c` where both `a -> c` and `b -> c` exist and `c` is acyclic
- **WHEN** DFS runs from `a` and then from `b`
- **THEN** the subtree rooted at `c` MUST NOT be re-traversed during the second invocation (visited-set short-circuits the second walk)

#### Scenario: Acyclic graph produces no cycles
- **GIVEN** a graph `subtotaal -> []`, `totaal -> [subtotaal]` (no back-edges)
- **WHEN** DFS runs from every node
- **THEN** the returned cycle list MUST be empty

### Requirement: Canonical Cycle Signature Deduplication
The system MUST deduplicate detected cycles by canonical signature so that entering the same cycle from two different DFS starting nodes produces only one entry in the reported cycle list. The signature MUST be computed by dropping the duplicated closing node from the cycle path and then rotating the remaining sequence so the lexicographically smallest node leads. The rotated sequence MUST be joined by `->` to form the signature. The first time a signature is produced the cycle MUST be appended to the output and the signature recorded; subsequent re-discoveries with the same signature MUST be suppressed.

#### Scenario: Same cycle entered from two starts yields one report
- **GIVEN** the cycle `a -> b -> a` is reachable from both starting nodes `a` and `b`
- **WHEN** DFS visits both starts in turn
- **THEN** the cycle MUST appear exactly once in the returned cycle list
- **AND** the canonical signature MUST be `a->b` (rotation starting at the lexicographically smallest node)

#### Scenario: Cycle rotated to lexicographically smallest start
- **GIVEN** the cycle path `[c, a, b, c]` (closing node duplicated)
- **WHEN** `canonicaliseCycle` runs
- **THEN** the duplicated closing `c` MUST be dropped
- **AND** the body `[c, a, b]` MUST be rotated so `a` (smallest) leads
- **AND** the returned signature MUST be `a->b->c`

#### Scenario: Single-node self-loop produces a single-name signature
- **GIVEN** the cycle path `[a, a]`
- **WHEN** `canonicaliseCycle` runs
- **THEN** the duplicated closing `a` MUST be dropped
- **AND** the body `[a]` is already canonical
- **AND** the returned signature MUST be `a`

#### Scenario: Empty cycle returns empty signature
- **GIVEN** an empty cycle path
- **WHEN** `canonicaliseCycle` runs
- **THEN** the returned signature MUST be the empty string

## Current Implementation Status
- **Implemented:**
  - `ComputedFieldHandler` (`lib/Service/Object/SaveObject/ComputedFieldHandler.php`) provides Twig-based expression evaluation with sandbox security policy
  - Save-time evaluation integrated into SaveObject pipeline (line ~3551)
  - Read-time evaluation integrated into RenderObject pipeline (line ~1041)
  - Cross-reference resolution via `_ref` namespace with MAX_REF_DEPTH=3
  - Sandboxed Twig environment with SecurityPolicy restricting allowed tags, filters, and functions
  - Graceful error handling (catch all Throwable, log warning, return null)
  - Result type casting (numeric strings to int/float)
  - `hasComputedProperties()` and `getComputedPropertyNames()` utility methods
- **NOT implemented:**
  - `evaluateOn: demand` mode (on-demand evaluation via API parameter)
  - Circular dependency detection between computed fields
  - Dependency-ordered evaluation (topological sort)
  - Aggregation functions for collections of related objects (`_ref_list`)
  - APCu memoization for read-time computed values via ICacheFactory
  - Batch recalculation background job when formula changes
  - Audit trail entries marked as `computed` source
  - Import/export awareness of computed fields
  - UI rendering as read-only with visual distinction
  - `dependsOn` metadata on computed attribute

## Standards & References
- **JSON Schema** -- Property definitions extended with `computed` attribute (vendor extension)
- **Twig 3.x** -- Template engine for expression evaluation with SandboxExtension for security
- **OpenAPI 3.0** -- `readOnly` property attribute for computed fields in API spec
- **JSON Schema `readOnly`** -- Standard way to mark fields as not user-writable
- **ADR-001** -- All data via OpenRegister; computed fields are part of the schema-driven data layer
- **ADR-006** -- Schema standards; computed attribute extends property definitions consistently
- **ADR-008** -- Backend layering; ComputedFieldHandler is a Service-layer component called by SaveObject and RenderObject
- **Related specs:** schema-hooks (hook execution order relative to computed fields), event-driven-architecture (CloudEvents include computed values)

## Specificity Assessment
- The spec is well-defined with clear scenarios for each evaluation mode and edge case.
- The ComputedFieldHandler implementation already covers the core save/read evaluation, cross-reference resolution, sandbox security, and error handling.
- Missing: circular dependency detection, topological sort for evaluation order, demand-mode evaluation, aggregation over collections, batch recalculation jobs, import/export awareness, UI rendering.
- Open questions:
  - Should the `_ref_list` syntax for collection aggregation be a distinct resolver or share the existing `resolveReferences()` method?
  - What is the maximum number of computed fields per schema before performance degrades?
  - Should computed field expressions be validated at schema-save time (pre-compilation check)?

## Nextcloud Integration Analysis

**Status**: PARTIALLY IMPLEMENTED

**What Exists**: ComputedFieldHandler is fully integrated into both SaveObject (save-time evaluation) and RenderObject (read-time evaluation). The Twig sandbox uses SecurityPolicy to restrict allowed filters and functions. Cross-reference resolution uses MagicMapper for related object lookups with depth limiting. Error handling catches all Throwable exceptions and logs warnings. The existing MappingExtension provides custom Twig filters (b64enc, json_decode, zgw_enum, etc.) and functions (generateUuid, executeMapping) that are available in computed expressions.

**Gap Analysis**: No demand-mode evaluation, no circular dependency detection, no dependency-ordered evaluation, no collection aggregation (_ref_list), no ICacheFactory memoization for read-time fields, no background batch recalculation when formulas change, no audit trail awareness of computed changes, no import/export handling, and no UI read-only rendering.

**Nextcloud Core Integration Points**:
- **IJobList (Background Jobs)**: Register a `QueuedJob` for batch recalculation when a computed field expression changes. Process objects in configurable batch sizes to avoid memory exhaustion.
- **ICacheFactory**: Use `createDistributed('openregister_computed')` for memoizing read-time computed values. Cache key: `{objectUuid}_{expressionHash}_{dataHash}`. TTL configurable per schema.
- **IEventDispatcher**: Listen to schema update events to detect computed field expression changes and trigger recalculation jobs.
- **Twig SandboxExtension**: Already integrated in ComputedFieldHandler with a curated SecurityPolicy.

**Recommendation**: The core evaluation engine is solid. Next priorities should be: (1) circular dependency detection and topological sort for evaluation order, (2) `_ref_list` collection resolution for aggregation use cases, (3) ICacheFactory memoization for read-time fields, (4) batch recalculation background job. The demand-mode and UI rendering are lower priority since the save/read modes cover most use cases.
