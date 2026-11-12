---
kind: docs
---

# Proposal: capability-rows-verified-c

## Summary

The row-by-row check of 12 November read every capability row from `ai-platform-assistant` to `rec-copy` against the scenarios of the requirement it records. Most rows were said in full, and several were relinked to a requirement that already says the missing part. Two rows had no requirement that says what the row claims. This change writes those two requirements.

## Rows this closes

| row | capability | what was missing |
|---|---|---|
| file-type-limit | Limit which file types may be uploaded | the recorded requirement only blocks executables; nothing says a file property refuses a type or size outside its own allow list |
| hist-admin-actions | Admin and configuration actions in the audit trail | the recorded requirement covers app settings; changes to authorisation rules, API tokens and record types leave no entry |

## What changes

- ADDED requirement in `file-actions`: a file property refuses a file outside its declared types or size.
- ADDED requirement in `enhanced-audit-trail`: changes to authorisation rules, API tokens and record types are on the audit trail.
