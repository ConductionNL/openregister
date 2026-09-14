# Design: anonymising-as-an-archival-outcome

## D-1: a fourth action, not a variant of destroy

Modelling anonymising as a destroy with exceptions gives a destruction
certificate for a record that still exists, and a retention clock that
thinks the record is gone. It is its own action, with its own nomination,
its own review answer and its own record.

## D-2: the profile is declared per schema, the choice is made per outcome

Which properties identify a person is a property of the record type and
changes rarely. Whether this kind of case should be anonymised is a policy
decision per outcome and changes with the Selectielijst. Separating them
means the policy can move without touching the field list, and the field
list is reviewed in one place.

## D-3: four treatments, because removal alone is not usable

Removing every identifying property leaves a record nobody can count by
district or by year, which defeats the purpose of keeping it. So a property
may also be replaced with a fixed value, replaced with a stable pseudonym
that keeps rows joinable without naming anybody, or generalised to a coarser
value. The profile says which per property.

## D-4: the act reaches the derived copies or it is not an anonymisation

An anonymised record that is still findable by name in the search index, or
whose old value sits in the history projection, is not anonymised. So the
index, the projections and any derived copy are part of the same act, and
the act fails as a whole if one of them cannot be reached.

## D-5: the trail keeps the fact and loses the values

The audit trail is immutable by design, which is exactly the tension here.
The resolution is narrow and deliberate: the values of the anonymised
properties are removed from the trail's stored diffs, and an entry recording
the anonymisation takes their place. The chain is re-sealed over the result,
and the fact that something was removed, by whom and under which decision,
is itself on the chain.

## D-6: refuse rather than half-anonymise

A legal hold, an unreachable derived copy, or a caller who may not read the
properties involved are all reasons to do nothing and say so. A partial
anonymisation is the worst outcome: the record reads as anonymised and is
not.

## D-7: reuse analysis (ADR-012)

- The `pseudonymise` mode of `specs/gdpr-data-subject-rights`: reused as the
  execution primitive.
- The nomination deriver, the destruction list and the review answers of
  `archiving-as-a-process-with-sign-off`: reused, one action added.
- The legal-hold check of `specs/archival-destruction-workflow`: reused.
- The recorded destruction path of `delete-window-and-recorded-destruction`:
  reused for the record of the act.
- No second retention engine and no second anonymiser.
