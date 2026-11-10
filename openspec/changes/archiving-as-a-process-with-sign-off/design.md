# Design: archiving-as-a-process-with-sign-off

## D-1. The process lives with the objects, because the evidence has to

D7's second option puts the process in a separate archive component. The
cost is not the integration, it is where the record ends up. An auditor in
2029 asks who approved the destruction of one dossier, and the answer has
to be in the system that held the dossier. That is why the decision moved
the process here and left the bytes with filinq.

## D-2. Nomination is derived at closure and written down, with its rule

A nomination computed on demand cannot be argued with later, because the
selectielijst will have changed. It is derived when the object reaches a
terminal state, written on the object with the selectielijst row and the
rule that produced it, and recomputed only by an explicit act that records
the recomputation.

## D-3. A preservation regime is not the archive state, and both are named

`object-archive-state` takes a finished object out of the working views:
it is where a closed case lives while it still has business use. A
preservation regime is what a dossier enters when that business use ends
and the Archiefwet clock is what governs it. Writing them as one state
would mean a product that cannot tell "closed" from "statisch", which is
exactly the Dutch distinction the candidate names.

## D-4. A list without a named reviewer is a list nobody reads

Approval already exists and it is addressed to a group. An item addressed
to a person appears on that person's worklist and can be chased. The
reminder frequency is declared, because a reminder every day is ignored
and a reminder once is missed.

## D-5. Transfer belongs at the moment of decision

Today an approval leads to destruction and the e-depot is reached
separately. The reviewer's real question has three answers, and recording
the third one somewhere else splits the decision history in two. The
review writes the choice; `edepot-transfer` does the work when the choice
is transfer.

## D-6. The mapping is validated before the transfer, not during it

An unmapped mandatory MDTO element discovered by the e-depot is a failed
transfer and a support call. The mapping is configuration with a
validator, and a transfer of objects whose mandatory elements are unmapped
is refused with the element named.

## D-7. The classification plan is a file, versioned

The archivist is handed a selectielijst as a file. Importing it as
versioned register objects, and diffing a new version against what is in
use, is what turns "we support selectielijsten" into something an
archiefinspecteur can check.

## D-8. kind

Code, in OpenRegister. filinq keeps formats, dossiq keeps the
resultaattype.
