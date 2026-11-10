---
kind: code
depends_on: [field-rules-by-state, lifecycle-declarative-conditions, calc-engine-scalar-functions]
---

# Proposal: rules-engine-operability

## Summary

A rule that an administrator cannot list, read back, try out or replay is
a rule nobody trusts. OpenRegister has three rule vocabularies written and
none built, and not one of them says what an administrator sees after the
rule is saved. This change adds the operator's half: the inventory in run
order, the run log that names why a rule did or did not fire, a ceiling on
what one run may touch, a dry run, a replay over the objects that already
exist, and one expression vocabulary across the three engines.

## Candidates and cluster

Cluster 19 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "The rules engine". Owner
openregister, size L, 17 candidates, three of them `must`:

C-tasks-and-phases-9, C-tasks-and-phases-17, C-tasks-and-phases-25,
C-tasks-and-phases-30, C-configuration-14, C-configuration-17,
C-configuration-25, C-configuration-29, C-configuration-33,
C-configuration-34, C-configuration-40, C-configuration-47,
C-configuration-50, C-configuration-58, C-configuration-65,
C-configuration-69, C-configuration-106.

Ledger rows named in the candidate notes: 11.29, 11.30. Passers: 12, eight
driven and four documented. dossiq: one `yes`, four `partial`, twelve `no`.

## The decision this rests on

**D3, option 2 for the engine, the JSON AST for computed values.** Ruben
took the recommendation. The engine is OpenRegister's flow guards extended,
which is `field-rules-by-state` plus `lifecycle-declarative-conditions`
plus the JSON-AST `CalculationEvaluator`, rather than DMN or a field-rule
DSL of our own. D3 also names what stays missing after all three land: a
rule whose condition reads a case-type property rather than a built-in
field, and a rule action that makes a field required. This change closes
neither of those; it makes the three engines operable by a functional
administrator.

## Why

The proving system is xxllnc-zaken, cited by the configuration lane at
`configuration.tsv:136`: "Rule engine (rule-engine/spec.md)", for the
candidate that rules are evaluated after every case mutation so no path
can skip them. Three more passers carry the rest of the cluster:

- **glpi**, `configuration.tsv:124`: "Rule simulation
  (front/rule.test.php, front/rulesengine.test.php, census section 3)". A
  rule is tried against sample input before it is saved.
- **glpi** again, `tasks-and-phases.tsv:28`: "Form conditions
  (src/Glpi/Form/Condition/Engine.php:70,234,252,
  EngineVisibilityOutput, EngineValidationOutput, EngineCreationOutput)".
  One condition vocabulary decides visibility, validation and creation
  separately, which is the shape D3 argues for.
- **valtimo**, `configuration.tsv:71`: "Value resolvers
  (value-resolvers/spec.md)". One expression language addresses the case
  data, the process variables and a linked register. The candidate note
  says it plainly: no row in 273 asks whether a product has one expression
  language or five.
- **znuny**, `configuration.tsv:133`: "Ticket attribute relations
  (AdminTicketAttributeRelations.pm, System/TicketAttributeRelations.pm,
  Acl/TicketAttributeRelations.pm)". Which values of one field are allowed
  follows from the value chosen in another, administered as a table. The
  note names the Dutch case: zaaktype to resultaattype to bewaartermijn is
  that table, and it is in the Selectielijst.
- **youtrack**, documented, `configuration.tsv:113`: "Projects > Workflow
  Rule Manager", which lists the rules attached to a project in run order
  with their errors and an on and off switch. The candidate note names the
  support question it answers: "waarom is de flow niet gelopen" is today
  answered from a log file.
- **jira-data-center**, documented, `tasks-and-phases.tsv:20` and `:22`:
  the rule debugger and the JQL limit on a lookup action. An automation
  that silently stops firing is how a termijnbewaking fails, and a rule
  that matches four hundred thousand objects because somebody dropped a
  filter is an outage.

**What exists here and does not close it.** `flow-engine` records a run
per node, with what each node received, returned and logged, and it names
creating, editing and running a flow as rights. That is the engineer's
artefact, per run. Nothing lists the rules that apply to a schema in the
order they run, nothing says why a rule did not match, nothing bounds a
run's blast radius, nothing simulates, and nothing replays. The three rule
changes in flight add vocabulary and no operator surface at all:
`field-rules-by-state` publishes `@self.fieldRules` on read,
`lifecycle-declarative-conditions` adds a JSONLogic `condition` on a
transition, and `calc-engine-scalar-functions` adds seven operators to the
evaluator.

## What changes

- **An inventory per schema, in run order.** One read returns every rule
  that can act on a schema: the lifecycle conditions, the state field
  rules, the calculations and the flows triggered by its objects, in the
  order they are evaluated, each with its source annotation, its last run,
  its last error and whether it is switched on. An administrator switches
  one off from that read.
- **A run log a functional administrator can read.** Every evaluation
  records the rule, the object, the verdict (fired, did not match,
  refused, errored) and, when it did not fire, the operand that decided
  it. A rule that has not fired in ninety days is visible as such.
- **A declared ceiling.** A rule declares `maxObjects` for one run. A run
  that would exceed it stops before the first write, records the refusal
  with the count it would have touched, and names the rule.
- **A dry run.** A rule is evaluated against a named object or a stored
  sample payload and returns the verdict and the writes it would make,
  without making them. Saving a rule is not a condition of trying it.
- **A replay over the objects that already exist.** A newly saved rule is
  applied to a bounded, previewed selection of existing objects as a
  background job, under the ceiling and with a per-object outcome. The
  replay is an act with an actor, not a side effect of the save.
- **Every mutation path evaluates the rules.** The evaluation point is
  the save pipeline, so the API, the import, a flow node and a bulk job
  reach it identically. A path that can skip the rules is a defect and is
  named in the tasks.
- **One expression vocabulary.** The JSON AST of `x-openregister-calculations`
  is the single expression surface for a computed value, a condition
  operand and a rule's write target, per D3. JSONLogic conditions and Twig
  `computed` stay supported and are documented as the two legacy forms,
  with the AST named as the one an administrator authors.
- **A dependent value table.** A property MAY declare that its allowed
  values follow from the value of another property, administered as a
  table of pairs rather than as a rule per pair.
- **An administered default.** A property MAY declare a default that is an
  expression in the same AST, evaluated on create, so a default is not
  restricted to a literal.

## Consumers

- **dossiq**: `field-rules-declared`, which the build plan names as the
  consuming half of this cluster and which stands at 0 of 3 tasks. dossiq
  declares per case type which fields are hidden, read only or required,
  and reads the inventory and the run log into the case-type editor rather
  than building a second rule screen.
- **decidiq, humaniq, pipelinq**: the same inventory over their own
  schemas. Every app in the fleet that declares a lifecycle inherits the
  operator surface without writing one.
- **nextcloud-vue**: the inventory and the run log are two lists over an
  existing envelope, so the components are the fleet's list components.

## ADRs

- ADR-031: the rule is declared configuration, not a branch in a
  controller. An operator surface over declared rules is the half that
  makes the ADR usable by someone who does not deploy code.
- ADR-022: one rules engine in the platform layer. A leaf app declares;
  it does not build a second engine.
- ADR-005: a rule that cannot be evaluated is a refusal, never a silent
  pass. The ceiling, the missing operand and the unresolvable reference
  all fail closed.

## Impact

- Extends: `flow-engine` (the inventory, the ceiling, the dry run and the
  replay), `object-lifecycle` (the evaluation point and the dependent
  value table), and the three changes in flight, which keep their scope.
- Affected code: `CalculationEvaluator` and `CalculationOnSaveListener`,
  `LifecycleValidationListener`, the flow run log, the schema annotation
  validator, and the save pipeline where the evaluation point is enforced.
- Backwards compatible: a schema that declares no ceiling, no dry run and
  no replay behaves exactly as today, and the inventory is a read.
- Size: L.

## Out of scope

- DMN, and a field-rule DSL of our own. D3 retired both.
- A rule whose condition reads a case-type property rather than a
  built-in field, and a rule action that makes a field required. D3 names
  both as still missing; they are the CT-2 extension of
  `field-rules-by-state`, not this change.
- Scripted form behaviour without a deployment (C-configuration-40). The
  candidate note names it as a supply-chain risk beside ledger row 11.45,
  and a scripting seam is a decision of its own.
- Rule presets and rules suggested from the case data
  (C-tasks-and-phases-30). The engine half rates `yes` already; the
  suggestion half is a hermiq question under D13.
- Automation built in the host platform rather than the product
  (C-configuration-50), which is a `could` with one documented passer.
