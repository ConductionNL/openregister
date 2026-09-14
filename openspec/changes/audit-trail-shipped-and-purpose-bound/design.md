# Design: audit-trail-shipped-and-purpose-bound

## D-1. The file is a sink, never a second truth

Two writable copies of an audit trail is two answers to the same question.
The database stays the trail and the hash chain stays over it. The file is
written from the same entries, in a documented format, for something else
to read.

## D-2. A sink that cannot be written is itself an entry

The failure mode of shipping is that the destination quietly stops
accepting and nobody notices for six months. A write failure is recorded
in the trail and surfaces on the operations console, because an unwatched
trail that everyone believes is being watched is worse than none.

## D-3. The purpose is bound to the processing register, not typed

A free-text purpose is a purpose nobody can report on. Each purpose is an
administered value bound to an entry in the processing activity register,
so "under which grondslag did we query the BRP" has one answer per query
and a report can count them.

## D-4. Before and after beats a payload copy

Keeping the request body answers "what did they send". The question people
actually ask is "which integration changed this field to this value", and
the before and after already on the entry answers it, with the token,
owner and consumer added. It also avoids a second store of personal data
with its own retention argument.

## D-5. The copy is made before removal is possible

A moderation copy taken when the delete runs is a copy that races the
delete. It is written when the report is filed, which is also when
somebody first believed the content mattered.

## D-6. Announcing is not recording

Redmine tells somebody at the moment a security setting changes; the
record is a separate thing that already exists here. Both are worth
having, and conflating them gives a mailbox full of everything or a record
nobody reads.

## D-7. kind

Code, in OpenRegister.
