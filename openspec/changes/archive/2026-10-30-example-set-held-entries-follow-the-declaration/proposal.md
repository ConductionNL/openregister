# A held change or preference follows its declaration

## Why
`--refresh --daily` on :8080 (REFRESH-6) exited 2 with seven fields "written but not kept". None of them was a write that failed:
- The record still held fields that the integriq stuf change no longer declares: lastReport, status, statusMessage and checkedAt, from before integriq #2740. integriq's health check owns those fields now. The proof compared them anyway.
- A package re-import with `--over-local-changes` put a checklist template back at name "Bouwtoezicht fase 3 - Oplevering", version 1. The set's change to "Bouwtoezicht oplevering", version 2 is held, so it was never written again.
- A refresh writes saved views again under a new uuid. Pieter's `pref_cn_saved_view_menu` still quoted the old uuid, and the menu then dropped the entry.

## What changes
- A held change lets go of every field its declaration no longer sets. The proof and remove leave such a field alone.
- On a refresh, a held change writes a declared field again in two cases: the field is back at what the change replaced, or it still holds what the change wrote while the declaration resolves to something new. A field with a third value is somebody's edit and is left.
- On a refresh, a held preference is written again when its declaration resolves to a different value than the set last wrote. What it first replaced stays recorded for remove.
- A field that the declaration of a held change gained is written on the next install and recorded. Measured: regulier v4 never received the `rightsMatrix` and `fieldRoleRules` its change added after the first install.
