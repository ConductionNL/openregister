---
description: Start an OpenSpec change for OpenRegister
argument-hint: "<change-name in kebab-case>"
---
Create a change folder `openspec/changes/$ARGUMENTS/`. Read `openspec/config.yaml` for the project rules, and one recent change (for example `openspec/changes/a-leaf-declares-how-it-loads/`) for the shape.

1. Check that `openspec/changes/$ARGUMENTS/` and `openspec/changes/archive/*-$ARGUMENTS/` do not exist yet.
2. Find the capability the change touches in `openspec/specs/<capability>/spec.md` and read it.
3. Write:
   - `proposal.md`: `## Why` and `## What Changes`.
   - `design.md`, only when there is a real design choice: the options and the decision.
   - `specs/<capability>/spec.md`: delta specs under `## ADDED Requirements`, `## MODIFIED Requirements` or `## REMOVED Requirements`, each `### Requirement:` with at least one `#### Scenario:` (GIVEN/WHEN/THEN).
   - `tasks.md`: numbered checkbox tasks, ending with the quality gate (`composer check:strict`) and tests.
4. Before writing a utility, search `lib/Formats/` and `lib/Service/` for an existing one (ADR-008 in `openspec/architecture/`).
5. Stop after writing the change and show it for review. Do not start building.
