## Context

portaliq's example resident (`lib/Service/ExampleResident/*`,
`lib/Settings/sites/residents/zuiddrecht.json`) is the working pattern: objects
per `register`/`schema`, a `key` per object, `lookups` by business key into
another app's register, `{{date:n}}` and `{{datetime:n HH:MM}}`, references
`{{object:key.field}}`, an install record in app config, exact removal, a
refusal to take over an account it did not make, and a read-back proof because
OpenRegister answers a write with the object as sent, also for a key the schema
does not keep. This change generalises that pattern to a whole set across apps
and moves it into OpenRegister, which owns the registers every fragment writes
into.

## Goals / Non-Goals

Goals: one command installs a coherent set across every enabled app; a cast
that lives once; refusal before the first write; exact removal; a proof.

Non-goals: generating data (ADR-111 mocks stay as they are); a UI; per-app
fragments other than OpenRegister's own (other lanes add them); changing
portaliq.

## File format

`<app>/lib/Settings/example-sets/<set>.json`:

```json
{
  "set": "zuiddrecht",
  "after": ["dossiq"],
  "objects": [
    {
      "key": "woo-fietspad",
      "register": "dossiq",
      "schema": "case",
      "lookups": {
        "caseType": { "schema": "caseType", "where": { "identifier": "woo-verzoek" } },
        "person": { "register": "brp", "schema": "ingeschreven-persoon",
                    "where": { "burgerservicenummer": "{{cast:sanne.bsn}}" } }
      },
      "data": {
        "title": "Verlichting fietspad Lindelaan",
        "caseType": "{{lookup:caseType}}",
        "applicant": "{{lookup:person}}",
        "startDate": "{{date:-2}}"
      }
    }
  ],
  "groups": [ { "group": "dossiq-behandelaars", "members": ["esra", "marleen"] } ]
}
```

OpenRegister's file additionally carries `title`, `timezone`, optional `apps`
(the apps the set expects; an absent one is named) and `cast`:

```json
"cast": {
  "groups": [ { "key": "vergunningen", "gid": "zuiddrecht-vergunningen", "displayName": "Vergunningen" } ],
  "people": [ { "key": "sanne", "uid": "sanne.devries", "displayName": "Sanne de Vries",
                "email": "sanne.devries@zuiddrecht.example", "role": "resident", "bsn": "999994001",
                "address": { "street": "Lindelaan", "number": 14, "postcode": "2999AA", "city": "Zuiddrecht" } } ],
  "organisations": [ { "key": "slagerij", "name": "Slagerij Van der Berg", "kvk": "00001001", "contact": "janwillem" } ]
}
```

A person without `uid` gets no account. A person's `groups` lists cast group
keys. A fragment's `groups` entries name a Nextcloud gid (created when absent)
and cast person keys.

**Why the cast sits in OpenRegister's own fragment file rather than a separate
file.** OpenRegister is also a contributor: the cast's BRP and KVK rows are
objects in OpenRegister's own `brp` and `kvk` registers, and they are best
written next to the cast that defines them (`{{cast:sanne.bsn}}` in the same
file). One file per app per set keeps discovery uniform: the loader reads the
same path in every app and only checks that `cast` appears in OpenRegister's.

## Decisions

1. **Object keys are scoped per fragment.** `{{object:k.field}}` resolves only
   within the same file, and the record keys objects as `<app>/<key>`. Another
   app's object is reached only through a lookup on a business key, so a
   fragment never depends on another fragment's internals, only on its data.
2. **Check before write.** Install first runs the full check: placeholders,
   lookups, cast, BSN eleven test, registers and schemas, and OpenRegister's
   `ValidateObject::validateObject()` against the real schema. Saving only
   validates when a schema has hard validation on, so the loader calls the
   validator itself. A reference to an object not written yet gets a stand-in
   (a UUID shape for `id`/`uuid`, the declared value for a declared field), and
   a lookup that finds nothing on the instance may match an object declared
   earlier in the set. Only when the pass is clean does the first write happen.
3. **Accounts.** New accounts get a generated password printed once, or `OC_PASS`
   with `--password-from-env`. The password never enters the record or a log
   line. An existing account the record does not list stops the install unless
   named by `--adopt <uid>`; adoption is recorded and remove never deletes an
   adopted account.
4. **Record.** `IAppConfig` key `example_set_<set>` in openregister, JSON with
   `users` (uid to `created`/`adopted`), `groups` (gids created), `memberships`
   (gid and uid pairs added) and `objects` (`<app>/<key>` to register, schema,
   id, in write order). The record is written after every step so an
   interrupted install still removes exactly.
5. **OpenRegister access through one adapter** (`ExampleSetStore`) fetched
   lazily from the container, the way portaliq's store does, so the occ command
   keeps a light DI graph (see the circular-DI note on `MigrateStorageCommand`
   in info.xml) and the services are testable with one mock.
6. **Generic names.** No class, method or command is named after a set,
   municipality or procedure. Zuiddrecht is only data.

## Test numbers

- **BSN**: `999994001`, `999994013`, `999994037`, `999994049`, `999994050`,
  `999994062`, `999994074`. All pass the eleven test and sit in the block
  999990000 to 999999999 that the BRP test data (RvIG proefomgeving) uses and
  that is not issued to residents. None of them appears in any register file in
  the fleet's `lib/Settings` (checked 13 Oct 2026; the BRP mock's own numbers,
  such as 999993653, are avoided).
- **KVK**: `00001001`, `00001002`, `00001003`, vestigingsnummers
  `000000001001` to `000000001003`. KVK numbers carry no check digit, so no
  range is provably unissued; these are fictitious, satisfy the schema's
  `^\d{8}$` and `^\d{12}$`, and collide with nothing in the fleet's registers or
  with KVK's published test set (`68750110`, `69599084` and so on), which the
  KVK mock already uses.
- **Addresses**: Zuiddrecht does not exist; its municipality code is `9999`,
  which no Dutch municipality has. Postcodes are well-formed (`2999AA` and up)
  but were not checked against the postcode table; an address is never a join
  key, so a coincidence with a real postcode links to nothing.

## Sanne de Vries: she moves onto the cast (decided 13 Oct 2026)

portaliq's `example-resident:install zuiddrecht` used to create
`sanne.devries`. Ruben chose to move portaliq onto the cast rather than have
the cast adopt portaliq's account: portaliq ships
`lib/Settings/example-sets/zuiddrecht.json` with `after: ["dossiq"]`, uses
`{{cast:sanne.uid}}` where it used `{{subject}}`, writes the portal account as
an object and the sign-in mode as a change on the portal (decision 8). Her five
cases, the question and the four messages stay exactly as they were, because
the Woo journey depends on them. That is a separate portaliq PR.

`--adopt` stays in the loader for an account somebody made by hand.

## Decisions of 13 Oct 2026

7. **Required registers.** The cast file names OpenRegister registers in
   `requires` (zuiddrecht: `brp`, `kvk`). They are mock registers (ADR-111
   rule 3), so a fresh instance lacks them. The loader imports a missing one
   from OpenRegister's own descriptor before the check, through
   `RegisterDescriptorService::reimport()`, and records the import job ids the
   import reports it ran under (never worked out from the jobs the instance holds
   before and after, which would claim an import another process recorded meanwhile).
   Remove soft-deletes exactly those jobs' objects (their uuids from the audit
   trail, `AuditTrailMapper::objectUuidsByImportJobId()`), last, without access
   rules like every other delete of the loader, and forgets the jobs. It does
   not elevate through `SystemOperationContext`: the caller is `occ`, and the
   elevation call-site set stays pinned (ADR-099 rule 9). A
   register that was already there is never imported or removed. The register
   and schema definitions stay; only the objects of the import go.
   Amended 15 Oct: `brp` and `kvk` are mock-only descriptors, and the
   real-configuration re-import (#4618) correctly refuses a mock, so a fresh
   instance refused the set. A `requires` entry now says which descriptor it
   wants: a slug for the real one, `{"register": "<slug>", "mock": true}` for
   the mock, imported through `RegisterDescriptorService::reimportMock()`
   under `openregister.mock`. The choice is the set's, not a command flag, so
   the nightly reset runs the same command as a hand install and a plain slug
   never quietly falls back to sample data.
8. **Changes to objects the set did not create.** A sign-in mode lives on the
   portal row that the site install made, so it cannot be a new object. A
   fragment's `changes` find one object by business key and `set` or `append`
   values at dot paths. Only what differs is written; the record keeps each old
   value, each appended value and any map made on the way, and remove restores
   exactly those. A change already fully present is not recorded, so remove
   leaves it.
9. **No administrators.** A cast or fragment group with gid `admin` refuses the
   set. Noor Yilmaz (functioneel beheer, CISO) gets app admin groups from the
   app fragments, never Nextcloud's.
10. **JSON text fields.** `jsonFields` on an object sends those fields as JSON
    text (portaliq's case `statusHistory`); the proof decodes them.
11. **Cast.** Marleen Postma is teamleider Vergunningen, in the Vergunningen
    group with Esra Demir. OpenRegister owns no team-lead group; app lead
    groups come from fragments. The cast file's `apps` lists the 18 apps that
    take part; learniq, larpinq and zaakafhandelapp do not.

## Decisions of round 3 (13 Oct 2026, from the fragment lanes)

12. **Files.** `files` on an object; paths are relative to the shipping app's
    `lib/Settings/example-sets/` folder and are checked twice: by shape when
    the set is gathered (no absolute path, `..`, backslash or NUL) and by
    `realpath` containment and readability in the check, so a symlink out of
    the folder is refused as well. Files are added with
    `FileService::addFile()` after the object is written (`share` passed on),
    recorded per object, and deleted before the object on remove. FileService
    has no field for a title or description, so those stay in the fragment.
13. **Service accounts.** Nextcloud has no account without a password and no
    per-account switch that blocks password sign-in while keeping the account
    enabled, and dossiq needs the account enabled. So a service account gets a
    long random password that is never shown, logged or recorded: nobody can
    sign in with it. It follows the cast's account rules (refuse a foreign
    account, `--adopt`).
14. **App config, own app only.** A fragment sets config of the app that
    ships it. No consumer needs more: dossiq and pipelinq set their own keys.
    A key stored as sensitive is refused unless `generate: true`, which writes
    a random value as sensitive and records no value. Remove restores the old
    value only while the key still holds the set's value.
15. **Organisations.** `{{organisation:slug-or-name}}` reads OpenRegister's
    organisation entities. The cast declares `openregisterOrganisations`;
    zuiddrecht declares the municipality (slug `zuiddrecht`) so opencatalogi's
    catalog and publications can name it. Created before the check like a
    required register, removed last and only when the set created it.
16. **contactsUid: a stable stand-in, no vCards.** Every cast person and
    organisation gets `example-set-<set>-<key>` unless it declares one;
    pipelinq's fragment already uses exactly these values. The loader does not
    write Contacts cards. Writing them needs `OCA\DAV\CardDAV\CardDavBackend`
    (dav's internal class, not OCP) and an address book owner; occ has no user,
    and `OCP\Contacts\IManager` only sees address books of a session.
    Recommendation: leave it to a follow-up in pipelinq, which owns the link
    to Contacts: a `pipelinq:contacts:sync` that writes a card per client with
    that uid into a shared address book. Until then a uid links to no card,
    which pipelinq tolerates (it only requires the field).
17. **Changes on objects of the same run** (`object: <key>`), and
    **weekday-pinned dates** (`@thu`), and **`{{instance:baseUrl}}`** from
    `overwrite.cli.url`, refused when the instance sets none.

## Risks / Trade-offs

- `--check` imports a missing required register. That is the one write a
  check makes, chosen because a check against schemas that are not there
  checks nothing; the import is recorded and remove takes its objects away.

- A lookup to a register that filters poorly falls back to reading the whole
  schema (as portaliq does), bounded by a page cap. Fine for demo sizes.
- A stand-in for an unwritten object's register-generated field (such as a case
  number) is validated as a string; a schema that patterns that field is
  validated for real only at write time, where a refusal ends with exit 2.
- Group names are global in Nextcloud. Cast gids are prefixed by the set's
  data, not by code.
