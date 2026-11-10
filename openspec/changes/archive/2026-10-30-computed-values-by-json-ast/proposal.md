---
kind: code
depends_on: [property-vocabulary-published, calc-engine-scalar-functions]
---

# Proposal: computed-values-by-json-ast

## Summary

OpenRegister ships two engines for a computed value and a functional
administrator can reach neither. Decision D3 picked one of them: the JSON
AST, because an administrator writes the expression and an auditor reads
it a year later. This change makes the AST the administered engine, by
publishing its operator catalogue, letting a leaf app's property form
forward a calculation, and saying plainly what the other engine is for.

## Candidates and cluster

Cluster CT-3 of `procest/_round4/discovery/build-plan.md`, from the depth
study `procest/_round4/discovery/casetype-configurability.md`
(ConductionNL/market-intelligence, 2026-09-14): "Computed values". Study
row B2, ledger row 3.17. Owner openregister, size S, depends on CT-1.

## The decision this rests on

**D3, second half: the JSON AST for computed values.** The decision text
is the argument: "Twig is more expressive and is a sandbox to keep safe;
the JSON AST is auditable, diffable and safe by construction, and a
functional administrator writes the expression while an auditor reads it a
year later."

## Why

The study's row B2 reads, for the eleven systems in its table:
xxllnc-zaken yes, jira yes, youtrack yes, glpi, tuleap, frappe and odoo
part, and dossiq no. Its note on dossiq is the whole problem in one line:

> `no key on propertyDefinition; nearest lib/Service/Transitions/SetFieldHandler.php`.
> OpenRegister ships computed Twig and x-openregister-calculations;
> neither is exposed.

The study's cluster 3 says where the wall is: "Neither is reachable from a
dossiq case-type property, because the extends-form map does not forward
them."

**What exists here and does not close it.** `computed-fields` carries both
engines and is thorough about them: save-time, read-time and on-demand
evaluation, cross-field and cross-object references, aggregation, string,
date and maths operations, circular dependency detection, computed fields
read-only in the API, an audit trail for computed values, and, for the
AST, "JSON-AST calculation expressions MUST be evaluated by a pure-function
evaluator" with schema-save-time validation of the annotation.
`calc-engine-scalar-functions` adds seven operators to that evaluator.

What is missing is the administrator. Nothing publishes which operators
exist, so a form cannot offer them. Nothing lets a leaf app's property
form carry a calculation, so an administered property cannot hold one. And
nothing says which of the two engines an authoring surface should offer,
so the honest answer today is neither.

## What changes

- **The operator catalogue is published.** One read returns every operator
  the evaluator accepts, its arity, its operand types, its result type and
  a sentence. An expression builder in any app is generated from it, and
  it stays correct when `calc-engine-scalar-functions` adds seven more.
- **A calculation is a forwardable property key.** A leaf app's property
  form may forward a calculation declaration, validated at schema save
  against the catalogue exactly as a hand-written annotation is, under the
  forwarding contract of `property-vocabulary-published`.
- **The AST is the authored engine, and the docs say so.** Twig `computed`
  stays supported for schemas authored in code. A property authored
  through an administration surface carries an AST calculation. The two
  are documented as what they are rather than as alternatives a reader has
  to choose between.
- **An expression is tried before it is saved.** A declaration is
  evaluated against a named object or a sample payload and returns the
  value or the error, without saving the schema.
- **The dependencies are derived, not declared twice.** The properties an
  expression reads are read out of the AST, so a dependency list cannot
  disagree with the expression, and the existing circular detection runs
  on the derived list.

## Consumers

- **dossiq**: `property-definition-management`, the same file as CT-1. One
  more key on `propertyDefinition` and one more entry in the forwarding
  map, which the study sizes at S, plus the expression builder in
  `PropertiesTab.vue`.
- **shillinq**: the fleet audit named in `calc-engine-scalar-functions`
  found about 43 declarative calculations that cannot be expressed today.
  A published catalogue is how an author knows which of them now can.
- **humaniq, pipelinq, decidiq**: a derived field per schema with no PHP.

## ADRs

- ADR-031: a computed value is declared, and an imperative handler for a
  derived field is the anti-pattern this closes.
- ADR-022: one calculation engine in the object layer.
- ADR-005: an expression that cannot be evaluated refuses the save and
  names the property; it does not write an empty value.

## Impact

- Extends `computed-fields` with the operator catalogue, the forwardable
  declaration, the pre-save evaluation and the derived dependency list.
- Affected code: `CalculationEvaluator` and its operator dispatch, the
  calculation annotation validator, the schema read and a discovery
  endpoint.
- Backwards compatible: existing Twig `computed` properties and existing
  `x-openregister-calculations` annotations are untouched.
- Size: S.

## Out of scope

- Retiring the Twig engine. D3 chose which one an administrator authors,
  not which one exists.
- Cross-object folding operators, which `calc-engine-scalar-functions`
  explicitly leaves to the aggregation engine.
- The rule engine's conditions and actions, which is CT-2 and the rules
  engine cluster.
