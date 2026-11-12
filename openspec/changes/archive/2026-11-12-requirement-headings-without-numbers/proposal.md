---
kind: docs
---

# Proposal: requirement-headings-without-numbers

## Summary

Two main specs, `referential-integrity` and `deletion-audit-trail`, number their requirement
headings (`### Requirement 3: ...`). Every other spec writes `### Requirement: ...`, and the
capability matrix records a requirement as `<path> :: <heading>` against that form. This change
drops the numbers from the 31 headings. The requirement text, the scenarios and the behaviour
stay the same.

## Why

Five capability rows (mod-relations, rec-bin, api-expand, hist-deleted-trace, q-dangling) rest on
these requirements. A strict reference check only accepts a `### Requirement:` heading, so their
references could not resolve while the headings carried a number.

## What changes

- The 16 numbered headings in `referential-integrity` and the 15 in `deletion-audit-trail` lose
  their number, edited in the main specs directly. OpenSpec does not parse a numbered heading as the
  FROM side of a RENAMED delta, so this change carries no delta (`skip_specs: true`).
