# Design: delete-window-and-recorded-destruction

## D-1. Extend the soft delete, do not build a second state

OpenRegister has three states either side of this problem already: live,
soft-deleted with a purge date, and archived. Adding a recycle state would
make four, with three of them meaning "not quite gone", and every consumer
would have to learn which is which. The soft delete is the window; it only
needs to say so.

## D-2. A window nobody can read is not a window

`purgeDate` exists in the deletion metadata today and no surface publishes
it. The fix is small and it is the whole candidate: the date and the days
remaining come back on the object, in the trash listing and in the 404 the
object now answers with, so a caseworker knows whether they have a week or
an hour.

## D-3. Destroying is a right, not an admin check

"Admin-only SHOULD be enforced" is the shape that produces a different
answer in every deployment. The verb is declared in the permission
catalogue that `permission-provenance-and-deny` publishes, granted like any
other, and refused with the rule that refused it. That is also what makes
"only the record manager may delete a document" expressible at all.

## D-4. The destruction scope is declared, because referential integrity
answers a different question

Cascade rules protect consistency: they decide what must not dangle.
Destruction asks what must not survive. A note on a destroyed case does
not dangle, and it does hold personal data. The schema declares the scope,
the act previews it, and the report says what went, so nobody has to infer
destruction from a foreign key.

What is never destroyed is the evidence that the destruction happened. A
destruction record with no object is the point.

## D-5. Two clocks, and the disagreement is an output

The AVG says delete when the purpose ends. The Archiefwet says keep for N
years. A product that merges them into one date is wrong in one direction
for every object. Both dates sit on the object, each with the rule that
produced it, and where the AVG date falls before the archive date the
object is not destroyed and the conflict is reported for a person to
decide. Silence here is the failure mode, not the conflict.

## D-6. Legal hold outranks both, and says so

`retention-management` already exempts a held object from the archive
clock. The AVG pass must respect the same hold, or a hold means one thing
on Monday and another on Tuesday.

## D-7. kind

Code, in OpenRegister. dossiq keeps its guard and reads the window.
