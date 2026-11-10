# Design: object-dates-as-a-calendar-feed

## D-1. Publish before writing, because a written event goes stale

A term moves. It is suspended, extended, recalculated after a working-day
change. An event written into somebody's calendar does not move with it
unless every mover remembers to rewrite it, and the one that forgets is
the one that misses a statutory deadline. A feed is generated from the
object on every refresh, so it cannot disagree with the record.

This is D11's recommendation exactly: option 1 as the model, option 3
delivered first.

## D-2. One feed per principal, resolved by the object rules

A calendar client sends no session. The feed is addressed by a revocable
token that names the principal, and the generator then answers with
exactly the objects that principal may list, through the same path the
object list uses, deny included. No second access rule is written for the
calendar, because a second access rule is how a leak happens.

A revoked token answers 404. An expired one answers 404. Neither answers
a partial calendar, because a partial calendar looks like an empty day.

## D-3. A date kind, not a date guess

Publishing every date property would fill a caseworker's agenda with
`created` and `modified`. A date publishes when the schema says what it
is. Three kinds cover the corpus: a deadline, an appointment and a period.
The kind decides the VEVENT shape and the alarm, so the leaf app declares
meaning rather than presentation.

## D-4. A deadline is an all-day event with a declared alarm

An all-day VEVENT on the deadline date is what the driven passers publish,
and it is what survives a time zone change. The alarm offset is on the
schema, because how long before a beslistermijn you want to be warned is a
policy of the organisation, not of the person.

## D-5. The working calendar decides the day

The term engine already computes the date a term actually falls on. The
feed reads that date, never a raw `+6 weeks`. Publishing a date the engine
would not enforce is worse than publishing nothing, because it is believed.

## D-6. Attendee answers are stored on the object

An invitation collects answers in the organiser's calendar, where the case
system cannot read them. For an appointment created from an object, the
responses are written back to the object as they arrive, so the record
answers "who is coming" and a later reader sees it without the organiser.

## D-7. kind

Code, in OpenRegister. The leaf apps declare date kinds and consume one
URL.
