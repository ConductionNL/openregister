## Why

Measured in the ten functional areas of ADR-012, the backend exposes 37.5% of its code to other areas and has a
violation degree of 0.194. That is an estimated 1.30 for component independence, 2.21 for entanglement and 2.85
for modularity (`docs/sig/component-analysis.md`). The causes are real: the foundation refers back to every
other area, and areas use each other's concrete classes. No test stops either from growing.

## What changes

- hydra ADR-119 (revised 2026-11-18) is the organisation-wide decision: a component is a functional area, areas
  depend in one direction, entities stay in their area, each area wires its own classes, and the measure is a
  test. This change applies it to OpenRegister and fixes OpenRegister's order of areas.
- A ratchet test and a deptrac baseline make every new reference against the order fail, and make every PR that
  removes some lower the recorded numbers.
- The code follows in waves: first the references that make the foundation point back, then the references
  between areas, then a narrow set of contracts per area.
- The milestones are 4.0 and then 4.5 for independence, entanglement and modularity in the functional view.

## What does not change

- No file moves out of the directories Nextcloud prescribes. The areas are patterns over those directories.
- No concrete class is renamed. Other apps keep resolving them by name.
- The declared components for the assessment stay the top-level directories (ADR-014).
- The behaviour of the app does not change. Every wave is a refactor with the existing tests green.
