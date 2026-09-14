# Design: several-legal-entities-in-one-instance

## D-1. Sharing is a declared read, not a copy and not a second tenancy

Copying a code list into every organisation guarantees that one copy is
wrong within a year. Reading the holder's rows through the same
tenant-scoped query path keeps one truth and keeps ADR-002 intact: the
organisation UUID is still the only tenant key, and the share is a rule
the query applies, not a second discriminator.

## D-2. A consumer cannot write shared master data

Read-only to the consumer is a property of the resolution, not of the user
interface. A screen that hides the save button is a screen somebody
bypasses with the API. The write is refused where the object is written,
and the refusal names the holder.

## D-3. The move is previewed per object type

GLPI's transfer asks, for each type under the record, whether to move it,
copy it or drop it. That question has no universal right answer: documents
usually move, a shared party is copied, an internal note is often dropped.
The preview lists every object and the policy that will apply to it, and
the operator approves that list.

## D-4. The move is one act on two chains

A case that leaves organisation A and arrives at organisation B is one
event that both need to be able to prove. It is written to both audit
trails with the same correlation, so neither side reads a record appearing
or vanishing with no cause.

## D-5. A log line that cannot be redacted is dropped

Redaction that fails open writes the secret. The rule is the other way
round: when the redactor cannot establish that a line is clean, the line
does not get written, and a counter records that it was dropped. A missing
log line is an incident; a logged token is a breach.

## D-6. The administration session is separate, the identity is not

Two accounts for one person is an access review problem. One identity with
a second, expiring session is the control the corpus asks for, and it is
what Plane ships: shared credentials, separate session.

## D-7. kind

Code, in OpenRegister.
