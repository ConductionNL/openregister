# Design: platform-collaboration-resources

## D-1. Access is the object's access, asked the same way

The platform asks the provider whether a user may access a resource. The
right answer is the one the object read path would give, resolved through
that path rather than reimplemented, so a deny stays a deny in the
collection too.

## D-2. Membership is a fact about the record

A per-user bookmark answers "what am I working on". A collection answers
"what belongs together", which is a property of the work. Anyone who may
update the object may add it to a collection or take it out, and the act
is recorded.

## D-3. A schema opts in

A code list has no business in a project collection, and offering every
register makes the picker useless. The schema declares whether its objects
may join, and the default is that they may not.

## D-4. kind

Code, in OpenRegister.
