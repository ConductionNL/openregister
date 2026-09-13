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
