# A calculation may be a fallback an app overrides

## Why
After REFRESH-7, `occ openregister:rematerialise-calculations dossiq case` reported 23 of 75 cases as "saved … but the save did not materialise: deadline". The inputs are present and the calculation returns a date (startDate plus the type's term).

dossiq's `CaseDeadlineFollowsTermListener` runs after the calculation listener, on purpose: it puts the statutory term's current end back, because a case deadline follows its term (extensions and suspensions) once one exists. The calculated deadline is only the fallback for a case without a term. The command read the term's date staying in place as a save that did not run the calculation.

## What changes
- A materialised calculation may declare `fallback: true`: its value stands until an app's own save listener sets one.
- `rematerialise-calculations` still re-saves such a row, but does not count a kept value as a failure.
- Every other calculation is checked as before.
- dossiq declares its case `deadline` calculation a fallback.
