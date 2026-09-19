---
kind: code
depends_on: []
---

# Proposal: platform-team-resource-provider

## Summary

`OCP\Teams\ITeamResourceProvider` puts a record on a team's own page. A
gemeente's afdeling opens its team and sees its files, its conversations
and nothing about its cases, because no provider offers them. This change
makes any object a team resource, so the work a team owns is on the page
the team already opens.

## The finding and the decision

Non-row finding 1 of `procest/_round4/discovery/candidates.json`
(ConductionNL/market-intelligence, 2026-09-14), said by
`nextcloud-deck.md`. The fifth of the ten is the team resource provider,
and Deck's own is cited in candidate C-access-and-privacy-24: "a record
appears on the team's own page as one of the team's resources",
nextcloud-deck, "lib/Teams/DeckTeamResourceProvider.php", with dossiq
`no`.

**D9, option 1 as taken by Ruben on 2026-09-14.** One programme, ten
interfaces, one change per interface.

## What openregister implements generically

- One `ITeamResourceProvider` answering, for a team, the objects that team
  owns, with each object's name, icon and link, and answering per object
  whether it belongs to a given team.
- **Ownership by a team is a declared property of the object**, not a
  second sharing model: an object names the team that owns it, and the
  permission layer already knows what that means.
- **The listing is paged and access-resolved**, so a team page opening on a
  register with a hundred thousand objects returns a page, not a timeout.
- **A schema declares whether its objects appear on team pages**, for the
  same reason a code list has no business there.

## What a leaf app declares

dossiq declares which schemas appear, and the property that names the
owning team. It registers no provider.

## What exists and what is missing

A search of the openregister openspec tree on 2026-09-14 returns no hits
for `ITeamResourceProvider` in `specs/` or `changes/`.
`rbac-department-role-matrix` carries a matrix keyed on a field of the
object such as `department`, which is the property this provider reads.
Nothing lists objects for a team.

## Impact

- Extends: a new `platform-team-resources` capability.
- Affected code: a provider class and its registration, the owning-team
  property resolution, the paged listing query.
- Backwards compatible: nothing appears on a team page until a schema
  declares it.
- Size: S.

## Out of scope

- Granting access by team, which the authorization layer already does and
  `rbac-department-role-matrix` specifies.
