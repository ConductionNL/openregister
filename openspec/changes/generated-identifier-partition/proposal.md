# Proposal: generated-identifier-partition

## Why

A generated identifier draws from one named counter per period. A register
that numbers per organisational unit (filinq's post register: every unit
starts at 1 each year) cannot say so: the sequence name is taken literally,
so `filinq-post-{unit}` is one shared counter with that odd name, not one
counter per unit. filinq keeps one instance-wide series until OpenRegister
can split a counter (filinq `documents-in-and-out-of-the-building` task 1.5).

## What Changes

- `x-openregister-generated` MAY carry `partitionBy`: the name of one
  property of the object. Objects whose property holds different values draw
  from different counters, each numbering from one in each period.
- An object that leaves that property empty (or holds a list or object
  there) draws from the unpartitioned counter, the one the sequence had
  before `partitionBy` was added, so adding it to a live schema changes
  nothing for existing objects.
- A supplied value advances the counter of its own partition.
- Schema save refuses a `partitionBy` that is not a plain property name.

## Impact

- `GeneratedIdentifierDeclaration` (+ interface), `GeneratedIdentifierListener`.
- No migration: the partition is part of the counter's scope key in
  `openregister_sequences`.

## Cross-app dependencies

- filinq `documents-in-and-out-of-the-building` 1.5 declares
  `partitionBy: "unit"` on `registrationNumber` once this lands.
