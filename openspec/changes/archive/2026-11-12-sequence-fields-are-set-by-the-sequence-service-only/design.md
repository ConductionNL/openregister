# Design: sequence-fields-are-set-by-the-sequence-service-only

No screen.

## D-1: refuse, do not drop

A dropped field turns a forged sequence step into a plain task without telling the caller. A refusal names the field, writes nothing and leaves an error a caller can fix.

## D-2: administrators too

An administrator reads every object, but a sequence position still comes from the chain declaration. The sequence service is the one writer, for every caller of `create()`.

## D-3: only the sequence-relevant part of the snapshot

`templateSnapshot` is also how a templated task freezes its template (`flow-tasks`), so it stays accepted. Only `approvingOutcomes` inside it is refused: it decides what approves the task, and the sequence copies it from its chain.
