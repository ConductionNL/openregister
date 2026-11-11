# declared-actions Delta

**Status**: proposed
**Scope**: openregister
**OpenSpec changes**:

- [macro-run-changes](../../)

## ADDED Requirements

### Requirement: A macro run's audit entry lists the changes it made

The `action.macro` audit entry SHALL carry `changes`: one entry per completed step of the run that wrote an object,
sent a message or created a task, in run order, each with the node's label (else its id) and the kind of change. Steps that changed
nothing or did not complete SHALL NOT be listed.

#### Scenario: Opschorten voor aanvulling

- **GIVEN** a macro whose flow writes the case ("Termijn opgeschort") and sends an email ("Bericht verstuurd")
- **WHEN** a handler runs it
- **THEN** the audit entry's `changes` lists those two steps, a write and a message
