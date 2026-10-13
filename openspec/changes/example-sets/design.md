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

## Sanne de Vries: adopt or move

portaliq's `example-resident:install zuiddrecht` already creates
`sanne.devries`. Two paths:

- **A. The cast adopts portaliq's account.** Works today with
  `--adopt sanne.devries`. Two installers then claim one account, and
  removing either leaves the other's record pointing at a person whose BRP row
  or account may be gone.
- **B. portaliq's resident moves onto the cast (recommended).** portaliq ships
  `lib/Settings/example-sets/zuiddrecht.json` with `after: ["dossiq"]`; its
  objects use `{{cast:sanne.uid}}` instead of `{{subject}}`, and the portal
  account and sign-in mode become objects in that fragment. The cast creates the
  account once. `portaliq:example-resident:install` stays for a portal-only
  instance until the fragment exists, then is retired.

Until B lands, `--adopt sanne.devries` is the interim.

## Risks / Trade-offs

- A lookup to a register that filters poorly falls back to reading the whole
  schema (as portaliq does), bounded by a page cap. Fine for demo sizes.
- A stand-in for an unwritten object's register-generated field (such as a case
  number) is validated as a string; a schema that patterns that field is
  validated for real only at write time, where a refusal ends with exit 2.
- Group names are global in Nextcloud. Cast gids are prefixed by the set's
  data, not by code.
