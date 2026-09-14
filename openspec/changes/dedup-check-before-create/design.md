# Design: dedup-check-before-create

## D-1: one scorer, two inputs

The existing service scores stored pairs. The check scores one unsaved
candidate against the stored set using the same rules and the same
normalisation, so a warning at intake and a later sweep agree on what a
duplicate is.

## D-2: advisory by default, blocking by declaration

Most schemas want a warning the user can wave through. A schema that must
never hold two of the same declares `block` and names who may override.
Both are data; the save path enforces `block` so the policy does not depend
on the client.

## D-3: bounded like the service

The candidate load is the object query with the schema's blocking keys as
filters (the rules' first-pass fields), capped as the service is. A check
never scans a register.

## D-4: kind

Code, in OpenRegister. Consuming apps declare and call.
