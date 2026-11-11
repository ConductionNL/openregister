# Design: sensitive-field-reveal-audit

## D-1: audit the reveal, not the check

A denial is already logged. What a data protection officer asks is who saw
the BSN, so the entry is written at the moment the value survives the
filter and is on its way to a client.

## D-2: once per request, batched

A detail read reveals a field once. A list read reveals it per row, which
is the finding the officer wants (forty citizens' numbers on one screen),
so every row counts, collected during rendering and inserted in one batch
at the end of the request.

## D-3: internal reads name the process

An export job or a retention sweep reads every object. One entry per row
would swamp the chain with a fact that has a better name: the job. Trusted
internal reads write one entry per run with the process identity.

## D-4: kind

Code, in OpenRegister. Consuming apps add one flag per property.

## Open questions (Ruben)

`RevealCollector::recordProcess()` (D-3, one entry per internal run) is built and tested but no run calls it. Trusted internal runs read with RBAC off, so they never pass `PropertyRbacHandler::recordReveal()`, which is where a reveal is noticed today.

1. **Which runs count as trusted internal reads of audited properties.**
   - Proposed: the export job, the retention and destruction sweeps, and the search-index rebuild; each names itself as the process and passes its run id.
   - Options: (a) as proposed; (b) every system-context read (`WriteCause` frame other than person or lookup); (c) only exports, since they are the runs that carry data out.
2. **How a run counts its reveals.**
   - Proposed: the run asks `RevealCollector::isAudited()` per schema once, counts the objects it read from schemas with audited properties, and calls `recordProcess(process, runId, count)` at its end.
   - Options: (a) as proposed; (b) a shared read wrapper for system reads that counts automatically; (c) record one entry per run without a count.
