# Proposal: example-set-refresh-trusted-writes

## Why

The first `--refresh` reinstall on the Zuiddrecht demo (REFRESH-3) exited 2: 11 objects were not refreshed and 5 values read as "written but not kept". The refresh sent the whole stored row back with today's declared values, so OpenRegister refused rows of append-only schemas, readOnly fields with a new value and lens fields; a status the declaration names was refused by the lifecycle guard; a read in the save path ran as an anonymous caller under occ. Two proof findings were the proof's own: a field kept as JSON text was compared as text against the decoded value, and a `#references` record kept the values of an earlier run after the refresh rewrote the object.

## What changes

- A refresh sends only what the schema lets an update change: a readOnly or immutable field carries its stored value, a lens is left out, a row of an append-only schema is kept as written.
- The store writes in the system scope (`ObjectService::runAsSystem`), as the configuration import does, and marks an update as a write of declared state; the lifecycle guard lets such a write set the declared status without a transition, only inside the system scope.
- After a rewrite the record of the object's references follows what was written; the proof compares JSON text with the value the store answers decoded.
