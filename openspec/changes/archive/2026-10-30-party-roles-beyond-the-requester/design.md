# Design: party-roles-beyond-the-requester

## D-1. A party is a role, and the role is the row

A property that points at a person answers one question: who is the
requester. Every other question needs a second property, and a case with
two gemachtigden needs a third. The corpus models this as a row per party
per record, and so do we: object, party, role, period. The role is the
thing the picker offers and the thing a permission reads.

## D-2. A party without an account is a party, not a user

Most melders never sign in. Making them Nextcloud users to hold a name and
an address is a permission surface nobody wants and an account nobody
uses. A party record holds its own properties and its own addresses, and
the notification engine resolves a recipient from the addresses rather
than from a user id. GLPI's `users_id = 0` beside `alternative_email` is
the same answer.

## D-3. An address has a kind, and one party has several

Two BRP rows for one resident is the accuracy problem; two records for one
caseworker with two employers is the same problem from the other side.
Addresses hang off the party with a kind, inbound resolution matches any
of them, and outbound picks by kind. Correspondence, case and location are
kinds, not three properties.

## D-4. An indicator declares its effect, so a reader cannot ignore it

An indicator that only renders is an indicator somebody misses. Each one
declares what it does: warn, refuse to publish, or refuse to send. A
protected address then blocks the publication rather than hoping the
publisher read the banner. The effect is evaluated where the act happens,
not in the component that draws the chip.

## D-5. The merge is `mdm-merge`, not a second merge

`mdm-merge` already has the preview, the atomic execution, the reversal
window and the audit register, and it is entity-type-agnostic by
requirement. Writing a party merge beside it would be two merges that
disagree about what happened. This change adds the party vocabulary and
nothing else.

## D-6. The accepted kinds are on the schema

A subsidy for an organisation and a Woo request from a citizen need
different pickers, and one declaration decides both. Putting the list on
the schema keeps the leaf app declarative and lets the validator refuse a
party kind the schema does not accept, at write time, in one place.

## D-7. The query cap is a refusal, never a truncation

A person search that silently returns the first ten looks like a search
that found ten. The cap refuses, names itself, and records the attempt.
That is the difference between proportionality and a page size.

## D-8. kind

Code, in OpenRegister. Leaf apps declare accepted kinds and consume one
party model.
