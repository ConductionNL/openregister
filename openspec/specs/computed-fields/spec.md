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
