---
kind: code
depends_on: [special-right-to-manage-grants]
---

# Proposal: an-object-lists-its-grants-with-their-origin

## Why

A person who works on a record wants to know who else can open it, and why. The board
DqZaakBetrokkenen (https://identity.conduction.nl/screens/board?id=dossiq/DqZaakBetrokkenen)
draws that answer as rows on its Toegang card:

- "Team Woo en informatie · via het zaaktype": a group that reads the case because a rule
  on the case schema names its case type.
- "Juridische zaken · gedeeld door Pieter Jansen": a group a colleague shared the case with.

OpenRegister holds every fact those rows need, spread over three reads that each answer a
different question, and none of them answers this one:

| read | what it gives | what the row is missing |
|---|---|---|
| `GET /api/objects/{r}/{s}/{id}/shares` (`ObjectSharingService::listGrants()`) | direct Nextcloud shares on the record's folder, plus the shares an ancestor confers (`InheritedGrantLister`) | who shared it (`IShare::getSharedBy()` is read and dropped), a display name, and every grant that comes from a rule |
| `GET /api/objects/{r}/{s}/{id}/permissions` (`ObjectPermissionsResolver`) | every principal named by the object, schema, register and role rules, with the level it is written at | a conditional rule is listed for every record, whether its `match` fits this record or not; no principal type, no display name |
| `GET /api/scopes` | the caller's own answer per verb | other people |

So a client that wants the rows has to call two endpoints, evaluate `match` clauses itself
and look up names. That client would be a second reader of the rules, which ADR-022 and the
ruling of 2026-09-19 (an-app-declares-object-access-rather-than-guarding-it) forbid: access
on an object is an OpenRegister feature, and apps consume it.

## What changes

1. A new read, `GET /api/objects/{register}/{schema}/{id}/grants`, answers with one row per
   principal and origin: who holds access, which verbs, where it came from and who made it.
2. Origins are generic: `owner`, `shared`, `inherited`, `rule`. A rule row names the level it
   is written at and the properties its condition matched on (`matchedOn`), so an app can
   label "via the case type" from configuration without OpenRegister knowing what a case
   type is.
3. A conditional rule appears only when its condition fits this record. A condition that
   depends on who is asking (`$userId`, `$user.groups`) is reported as relative, with the
   property it reads, rather than evaluated for the caller.
4. A shared or inherited row carries `sharedBy` with the sharer's display name.
5. A new grantable verb, `readGrants`, lets a schema name who may read this list beyond the
   owner, the owning group, administrators, written `manage` holders and `grant_manage`
   holders. It never resolves default-open.

## What does not change

- The existing `/shares` and `/permissions` reads keep their shape and their guards.
- Nothing here grants or revokes. A row is never a handle for revoking: an inherited row
  carries the ancestor's share id, and a revoke driven from it would remove the ancestor's
  grant (the reason `InheritedGrantLister` is read only).
- No access decision moves. The list reports what `PermissionHandler`,
  `ObjectGrantResolver` and `MagicRbacHandler` decide; it decides nothing.

## Consumers

- dossiq, change `case-access-card-lists-team-and-group-grants`: the Toegang card on the
  Betrokkenen tab wires its team and group rows to this read.
- Any app with a "who can open this" panel: the same rows, labelled from its own config.

## Found while reading

`appinfo/routes.php` registers `GET /api/objects/{register}/{schema}/{id}/shares` twice:
`objectSharing#shares` (line 117) and `shareLinks#index` (line 811). The first one wins, so
`ShareLinksController::index()` is unreachable on that path. Not fixed here.
