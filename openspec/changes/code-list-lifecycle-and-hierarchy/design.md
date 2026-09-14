# Design: code-list-lifecycle-and-hierarchy

## D-1. Expire, never delete, and keep resolving

A value that disappears breaks every record that holds it. A concept
outside its validity window stops being offered and keeps resolving, with
its label, for reads. That is the only behaviour that is correct both for
new data and for a dossier from 2019.

Deprecation, which the importer already sets, answers "the source dropped
it". A validity window answers "the organisation retired it on this date".
Both are needed, and they are not the same fact.

## D-2. A typed scheme, because a code list item is an object

A resultaattype with a bewaartermijn is an object with two properties. The
scheme declares the shape and the concepts are validated against it, so
the fields are typed, exportable and queryable rather than being a blob on
a label. This is why the code list belongs in the object layer at all.

## D-3. The hierarchy is used at the property, not copied

`broader` and `narrower` already round-trip. What is missing is a property
saying "my values are the narrower concepts of this branch". The
declaration is a branch and a leaf rule; the tree is read live, so moving
a concept in the scheme changes every property that points at the branch,
with no schema save anywhere.

A branch filter matches narrower concepts by walking the relation at query
time, bounded by depth, and not by writing a denormalised path that goes
stale.

## D-4. A context-bound subset is a declaration, not forty fields

"One field, a different list per case type" is one property whose options
are narrowed by the value of another property or by a declared context
key. It is the same shape as the dependent value table in the rules-engine
work, applied to a scheme rather than to a literal list, and it is written
once so the two do not diverge.

## D-5. A semantic role is a promise to the list, not a rename

Declaring which property means the title, the status, the assignee and the
term lets one list component serve every schema. It does not rename
anything and it does not change the data. It is the cheapest half of what
a configurable list needs, and it is what dossiq hard-codes today.

## D-6. A type conversion is previewed, and mostly refused

Changing a property's type on populated objects is dangerous, which is why
the honest answer is a short list of supported conversions, a preview over
the stored values and a refusal with a reason for everything else. Our own
notes already record that adding a `format` to a property is breaking. The
product's job is to say so before the save, not after.

## D-7. Uniqueness has two actions, because one of them is advisory

GLPI's field unicity offers refuse and notify, and both are real: a
gemeente wants "one bezwaar per besluit per indiener" refused, and wants
"two contacts with the same e-mail" reported without blocking the intake.
The constraint declares which it is.

## D-8. kind

Code, in OpenRegister. Consuming apps declare schemes and roles.
