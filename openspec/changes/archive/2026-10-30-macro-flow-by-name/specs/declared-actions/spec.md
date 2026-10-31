# declared-actions Delta

**Status**: proposed
**Scope**: openregister
**OpenSpec changes**:

- [macro-flow-by-name](../../)

## ADDED Requirements

### Requirement: A macro can name a flow its schema declares

A macro binding's `flow` MAY name a flow the same schema declares in `x-openregister-flows`. Saving the schema SHALL
accept it when that declaration has a manual trigger and SHALL refuse it when it has none; a name no stored or declared
flow answers to SHALL be refused. Running the macro SHALL run the stored flow the declaration was imported as.

#### Scenario: dossiq ships "Opschorten voor aanvulling"

- **GIVEN** the case schema declares the flow "Opschorten voor aanvulling" with a manual trigger
- **AND** the action `suspend-for-supplement` binds it by that name
- **WHEN** the register is imported and a handler runs the macro
- **THEN** OpenRegister runs the stored flow the declaration became
