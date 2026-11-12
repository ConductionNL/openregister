# Proposal: validate-stored-null-as-absent

## Why

OpenRegister stores an empty string as null. A non-required property with an enum that lists `""` (pipelinq's `salesContract.renewalLeadOutcome`: "", won, lost) is therefore read back as null. Every later validation of that object failed with "The data (null) must match the type: string". That covers a user's edit in the app and an example-set change, which then refuses the whole set.

**The cause:** `validateObject()` drops a null on an enum property whose enum does not allow null, but it asked with a loose `in_array(null, $enum)`. In PHP `null == ""`, so an enum listing `""` read as allowing null, and the null was kept. Schema preparation asks the same question strictly and does not widen the type, so the kept null failed.

## What changes

The null check in `validateObject()` is strict. A null on a non-required enum property whose enum does not list null is treated as absent, as the code already intends for every other enum. A required property still fails on null, and an enum that lists null still accepts it.
