# Tasks: party-roles-beyond-the-requester

## 1. The party role

- [x] 1.1 A party row on an object: party, role, period, with more than one party per role (D-1). `openregister_contact_links` gains `party_uuid`, `party_kind` and `primary_party`; the unique key is already `(object_uuid, contact_uid, role)`, so two parties hold one role and one party holds several.
- [x] 1.2 A schema declares the party kinds and roles it accepts; the validator refuses the rest, naming the kind (D-6). `configuration.partyKinds`, each entry optionally binding roles to that kind.
- [x] 1.3 Replacing the primary party is an authorised act and writes one audit entry naming both parties (D-1). `PartyRoleService::replacePrimaryParty`, action `party.primary-replaced`.

## 2. The party without an account

- [x] 2.1 A party carries its own properties with no Nextcloud account (D-2). A party is an object of a schema that declares `x-openregister-party`; the declaration names which property carries what.
- [x] 2.2 Addresses hang off the party with a kind: correspondence, case, location (D-3). A bare string still reads as a correspondence e-mail, so a register holding one address per party keeps working.
- [x] 2.3 The notification recipient resolver reads a party's addresses, not only a user id (D-2). `PartyNotificationService` plus `EmailSender::sendToAddress`.
- [x] 2.4 Inbound resolution matches any address the party holds and creates no second party (D-3). `PartyService::resolveByAddress`: the search narrows, the exact comparison decides.
- [x] 2.5 An organisation party names a parent, with cycle and depth guards. `PartyService::assertParentAllowed`, depth from the declaration.

## 3. Indicators

- [x] 3.1 An indicator on a party declares its effect: warn, refuse publication, refuse send (D-4). An unknown or absent effect reads as warn rather than vanishing.
- [x] 3.2 The effect is evaluated at the act, and a refusal names the indicator and the party (D-4). Publication is guarded in `FilePublishingHandler::publishFile`, the send in `PartyNotificationService`.
- [x] 3.3 The indicator is readable on every object the party holds a role on, without writing those objects. The link table is indexed on `party_uuid`, so the read runs from the party's side.

## 4. Merge and the query cap

- [x] 4.1 A party merge vocabulary over `mdm-merge`: surviving properties, address union, role carry-over (D-5). `PartyMergeListener` on `ObjectsMergedEvent`; the merge itself is untouched.
- [x] 4.2 The reversal window restores both parties and their roles (D-5). Every carried row records the operation that moved it, so the reversal is a read of the rows rather than surgery on the merge snapshot.
- [x] 4.3 An administered cap on a party query; over the cap is a refusal naming the cap, never a truncation (D-7). The count is taken before anything is read.
- [x] 4.4 The refused attempt is written to the audit trail with actor and query. `AuditTrailMapper::createPartyQueryRefusalEntry`, action `party.query-refused`.

## 5. Tests

- [x] 5.1 `tests/e2e/ci/party-roles.spec.ts`: two gemachtigden on one case, replace the primary party, read the audit entry, the inbound address, the indicator across two cases, the refused publication, the capped query and a real merge. Written and tagged; not run locally (no Playwright on the build host).
- [x] 5.2 Unit tests: the accepted-kind refusal, the address union, the inbound match, the cycle guard, the three indicator effects, the cap refusal. Thirty-seven tests under `tests/Unit/Service/Party/` and `tests/Unit/Listener/PartyMergeListenerTest.php`.
- [x] 5.3 A regression test that a schema declaring no party kinds behaves as today. `PartyDeclarationTest::testASchemaDeclaringNoPartyKindsBehavesAsBefore` and `PartyRoleServiceTest::testASchemaDeclaringNoKindsAcceptsAnyParty`.
- [x] 5.4 `openspec validate party-roles-beyond-the-requester --strict`.

## 6. Hand over

- [x] 6.1 Hand the party model to the dossiq lane for the per case type declaration, with the sixteen candidate ids. The contract is in `docs/Features/parties.md`.
- [x] 6.2 Hand the party record to the integriq lane as the target CT-5's adapters write into. The same document, "What integriq writes into".

## Left for a follow-up, deliberately

- A `parties` recipient kind inside `AnnotationNotificationDispatcher`, so a
  declarative schema rule can address the parties on an object the way it
  addresses watchers today. The resolver answers in verified uids and a party
  without an account has none, so widening that contract is its own change.
  `PartyNotificationService` is the unit the dispatcher will call.
- The picker and the parties block in the frontend. This change is the object
  layer the leaf apps consume; dossiq's lane owns the case-type declaration and
  the block that renders it.
