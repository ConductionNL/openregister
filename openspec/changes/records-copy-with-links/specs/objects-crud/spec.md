# objects-crud

## ADDED Requirements

### Requirement: An object can be copied with the links the caller chooses

`POST /api/objects/{register}/{schema}/{id}/copy` SHALL create a new object
with a new uuid from the source's fields and the given `overrides`, through the
normal create path. With `include`, it SHALL also re-create the source's
relation rows (`relationRows`), add the copy beside the source in array-valued
references that point at the source (`incoming`), and copy the attached files
(`files`), each under the caller's own rights. The response SHALL list every
link with `copied` or `skipped` and a reason.

#### Scenario: a catalogue editor copies an application with its links

- **GIVEN** a catalogue editor with `create` on schema `module`, and module "Zaaksysteem A" with two relation rows, listed in suite "Basis" through the array reference `applications`
- **WHEN** the editor calls `POST /api/objects/catalogus/module/{id}/copy` with `overrides: { "naam": "Zaaksysteem B" }` and `include: ["relationRows", "incoming"]`
- **THEN** the answer is 201 with the new module "Zaaksysteem B" and a new uuid
- **AND** the copy has both relation rows, suite "Basis" lists both modules, and "Zaaksysteem A" is unchanged
- @e2e exclude {specified only; task 2.1 adds tests/e2e/ci/copy-with-links.spec.ts}

#### Scenario: a link the editor may not write is reported, not forced

- **GIVEN** the same copy, where suite "Basis" belongs to an organisation the editor may read but not update
- **WHEN** the copy runs
- **THEN** the copy is created and its relation rows are copied
- **AND** the `incoming` entry for "Basis" is `skipped` with the reason that the editor may not update it
- @e2e exclude {specified only; covered by CopyObjectTest in task 1.1}

#### Scenario: a failed create leaves nothing behind

- **GIVEN** a copy whose `overrides` violate the schema
- **WHEN** the copy runs
- **THEN** the answer is 422 with the validation errors, and no object, relation row or reference was written
- @e2e exclude {specified only; covered by CopyObjectTest in task 1.1}
