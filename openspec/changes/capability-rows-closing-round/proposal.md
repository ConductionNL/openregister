---
kind: docs
---

# Proposal: capability-rows-closing-round

## Summary

The 12 November audit of Open Register's capability matrix found three built rows whose recorded
requirement says only part of what the row claims. This change writes the missing requirements,
so each row records a heading whose scenarios say the row. The code already does each of them.

## Rows this closes

| row | capability | what was missing |
|---|---|---|
| file-extract | Extract the text from attached PDF, Word and mail files | text-extraction only had a refactor rule; no scenario extracts a PDF, Word or mail file |
| mod-register-group | Group related record types into a register that is managed and shared as one unit | data-import-export covers sharing a configuration, not a register grouping its schemas |
| rec-lock | Lock a record while you edit it, with your name shown to anyone else who opens it | object-lifecycle covers the lock-state contract, not the lock holder's name shown to others |

## What changes

- ADDED requirement in `text-extraction`: the text of an attached PDF, Word or mail file is extracted.
- ADDED requirement in `data-import-export`: a register groups record types and moves as one unit.
- ADDED requirement in `object-lifecycle`: someone who opens a locked record sees who holds the lock.
