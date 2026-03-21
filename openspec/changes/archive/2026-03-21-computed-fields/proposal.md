# Computed Fields

## Problem
Computed fields enable schema properties whose values are derived automatically from expressions evaluated against object data, cross-referenced objects, and aggregation functions. This capability eliminates redundant data entry, ensures consistency of derived values (full names, totals, expiry dates), and brings spreadsheet-like formula power to OpenRegister without requiring external workflow engines for simple calculations. Computed fields use Twig expressions evaluated server-side, leveraging the existing Twig infrastructure already integrated into OpenRegister for mapping and transformation.

## Proposed Solution
Implement Computed Fields following the detailed specification. Key requirements include:
- Requirement: Schema Property Computed Attribute Definition
- Requirement: Save-Time Evaluation
- Requirement: Read-Time Evaluation
- Requirement: On-Demand Evaluation Mode
- Requirement: Cross-Field References Within the Same Object

## Scope
This change covers all requirements defined in the computed-fields specification.

## Success Criteria
- Define a computed property with string concatenation
- Define a computed property with numeric calculation
- Define a computed property with date calculation
- Reject a computed attribute without an expression
- Computed attribute with explicit dependsOn declaration
