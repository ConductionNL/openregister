# Tasks: party-roles-beyond-the-requester

## 1. The party role

- [ ] 1.1 A party row on an object: party, role, period, with more than one party per role (D-1).
- [ ] 1.2 A schema declares the party kinds and roles it accepts; the validator refuses the rest, naming the kind (D-6).
- [ ] 1.3 Replacing the primary party is an authorised act and writes one audit entry naming both parties (D-1).

## 2. The party without an account

- [ ] 2.1 A party carries its own properties with no Nextcloud account (D-2).
- [ ] 2.2 Addresses hang off the party with a kind: correspondence, case, location (D-3).
- [ ] 2.3 The notification recipient resolver reads a party's addresses, not only a user id (D-2).
- [ ] 2.4 Inbound resolution matches any address the party holds and creates no second party (D-3).
- [ ] 2.5 An organisation party names a parent, with cycle and depth guards.

## 3. Indicators

- [ ] 3.1 An indicator on a party declares its effect: warn, refuse publication, refuse send (D-4).
- [ ] 3.2 The effect is evaluated at the act, and a refusal names the indicator and the party (D-4).
- [ ] 3.3 The indicator is readable on every object the party holds a role on, without writing those objects.

## 4. Merge and the query cap

- [ ] 4.1 A party merge vocabulary over `mdm-merge`: surviving properties, address union, role carry-over (D-5).
- [ ] 4.2 The reversal window restores both parties and their roles (D-5).
- [ ] 4.3 An administered cap on a party query; over the cap is a refusal naming the cap, never a truncation (D-7).
- [ ] 4.4 The refused attempt is written to the audit trail with actor and query.

## 5. Tests

- [ ] 5.1 `tests/e2e/ci/party-roles.spec.ts`: two gemachtigden on one case, replace the primary party, read the audit entry.
- [ ] 5.2 Unit tests: the accepted-kind refusal, the address union, the inbound match, the cycle guard, the three indicator effects, the cap refusal.
- [ ] 5.3 A regression test that a schema declaring no party kinds behaves as today.
- [ ] 5.4 `openspec validate party-roles-beyond-the-requester --strict`.

## 6. Hand over

- [ ] 6.1 Hand the party model to the dossiq lane for the per case type declaration, with the sixteen candidate ids.
- [ ] 6.2 Hand the party record to the integriq lane as the target CT-5's adapters write into.
