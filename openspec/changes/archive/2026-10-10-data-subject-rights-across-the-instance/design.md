# Design: data-subject-rights-across-the-instance

## D-1. The preview counts, and it counts what it cannot touch too

An erasure preview that lists only what will be erased hides the
interesting half: the objects a legal hold or a retention period protects.
A gemeente needs both numbers to answer the subject honestly, because
"deels niet, en dit is waarom" is the lawful answer under the Archiefwet.

## D-2. An unresolvable hold counts as held

When the preview cannot establish whether an object is held, it counts it
as held and names it. Erasing on an unknown is the one failure that cannot
be undone.

## D-3. Erasure destroys through the recorded destruction, not beside it

D10 left one delete path with a stated window and a recorded destruction.
An erasure that deleted its own way would produce records nobody can
reconcile with the destruction log. It calls that path, and the
destruction record names the data subject request.

## D-4. The subject's export is a job with an expiring file

Assembling everything about a person is slow and the result is the most
sensitive file the instance will produce. It runs as a background job, is
delivered as a file with its own expiry, and the delivery is audited like
any other export.

## D-5. Reach is read from the resolver, never walked over screens

"Everything this person can reach" is the reverse of the query
`permission-provenance-and-deny` compiles. Reading it from the resolver
gives one answer that stays true as the rules change; walking the surfaces
gives an answer that is stale the moment a rule moves.

## D-6. An external grant without an end date is refused

Optional expiry is expiry nobody sets. A grant to a principal outside the
organisation carries an end date or it does not exist, and the holder is
warned before it lapses so the work is not lost mid-sentence.

## D-7. kind

Code, in OpenRegister.
