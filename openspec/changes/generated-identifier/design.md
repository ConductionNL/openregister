# Design: generated identifier

## D-1: annotation shape

`x-openregister-generated: { sequence: 'case', format: 'Z-{year}-{seq:5}',
resetOn: 'year' }`. `{seq:n}` zero-pads to n digits; `{year}`, `{month}`
render the creation time; any other text is literal. `resetOn: year` keys
the counter by year, so `Z-2026-00001` and `Z-2027-00001` are distinct
counters. Names are English (decision D13).

## D-2: one row per counter, taken under a lock

`openregister_sequences (name, period, value)` with a unique key on
(name, period). Taking the next value is `UPDATE ... SET value = value + 1
RETURNING value` on Postgres and a `SELECT ... FOR UPDATE` pair on MariaDB,
inside the object create transaction. A rolled-back create leaves a gap;
gaps are allowed, reuse is not.

## D-3: generated once, then frozen

The listener fills the property only when it is empty on create, in the
same pattern as `LifecycleInitialStateListener`. An update that changes a
generated property is refused with 422 by a guard on `ObjectUpdatingEvent`.
An import that supplies a value keeps it and advances the counter to at
least the parsed `{seq}` of that value, so later creates do not collide.

## D-4: no Twig

A Twig expression runs without a lock and can produce the same value twice
under load. The sequence is a distinct annotation for that reason, and the
generated value may still be referenced by computed fields afterwards.

## D-5: kind

Code, in OpenRegister. Consuming apps add the annotation to a property,
which is config.

## D-C41-1. A random identifier is a sequence kind, not a second mechanism

osTicket ships `RandomSequence` beside its counter and both allocate under
the same lock. Keeping one allocation path with two kinds means the
uniqueness argument is made once, which is the part that is hard to get
right under concurrency.

## D-C41-2. A foreign identifier names its issuer

An identifier from another system with no issuer is a string that collides
sooner or later. Each foreign identifier names the system that issued it,
which makes the lookup exact and lets two senders use the same number
without ambiguity.

## D-C41-3. A rewrite keeps the old number findable

A zaaknummer already quoted in a brief cannot stop working because the
scheme changed. The old value is kept as a foreign identifier issued by
this instance, so every letter ever sent still finds the record.

## D-C41-4. One migration per sequence at a time

Two concurrent renumberings of one sequence is a collision the lock cannot
help with, because both are legitimate writers. The second is refused,
naming the running one.
