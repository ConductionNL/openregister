## Why

Who holds a role on an object is part of that object. Linking a contact person to an organisation, replacing a case's
primary party or taking a gemachtigde off a case changes the record as much as editing one of its fields does.

Today the three party writes (`POST .../parties`, `PUT .../parties/primary`, `DELETE .../parties/{partyUuid}`) only
check that the caller may read the object. So anybody who can open an organisation can change who speaks for it,
and the schema's `update` rule is not asked.

dossiq's organisation contact persons (board DqOrganisatie, "Hoofdcontact") need exactly this rule: editing them is
the organisation's update right, enforced on the server and not only in the UI.

## What Changes

- The three party writes ask `PermissionHandler::checkPermission(update)` on the object, with its owner and the
  object itself (so conditional `match` rules apply), before anything is written. The same verdict every other
  object write gets.
- A refusal answers 403 and writes nothing: no link, no primary change, no audit entry, no event.
- Reading the parties (`GET .../parties`) stays a read: it needs the read right only, as before.
- Trusted paths (an example set's `importParty`, a `SystemOperationContext`) are unchanged: they do not run through
  the controller, and the permission handler already trusts a system operation.
