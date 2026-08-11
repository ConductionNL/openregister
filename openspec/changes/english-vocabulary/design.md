## Context

openregister was scoped in the fleet policy as "2 schemas / 20 properties — mostly
BRP/KVK wire fields". Both halves of that were wrong, and the correction inverts the
change.

A token-aware rescan, excluding the gitignored `custom_apps/` tree and the
`lib.pre-orgcred.bak/` backup directory, measures:

- **openregister's own schemas: 0 Dutch schemas, 0 Dutch properties.** Already clean.
- **Mock registers: 15 schemas / 79 properties**, all inside five files that describe
  themselves as mirrors of published national standards.
- **Code: 6 files, 6 classes, 22 methods.**

So this is a code-layer change with a marking exercise attached — not a schema rename.

## Goals / Non-Goals

**Goals:**

- Mark the five mock registers as wire so their vocabulary is protected by an explicit,
  reviewable statement rather than by nobody happening to touch them.
- Rename openregister's own Dutch classes, methods and files.
- Keep every emitted wire string byte-identical.

**Non-Goals:**

- Renaming anything inside `bag_`, `brp_`, `dso_`, `kvk_` or `ori_register.json`.
- Resolving the ZGW `Zaaktype` question, which belongs to procest.
- Touching the schema layer, which is already English.

## Decisions

### 1. A mock register is the wire, and the exemption is per-layer

The fleet policy's carve-out is for "statutory wire field names, inside the adapter layer
only". A mock register is not adjacent to the adapter layer — it *is* an executable copy
of an external contract, kept so a connector can be developed without hitting the real
registry.

`nummeraanduiding`, `verblijfsobject` and `pand` are BAG's own object types.
`ingeschreven-persoon` is Haal Centraal's own resource. Rename them and a connector
developed against the mock stops working against production BAG, which is precisely and
only what the mock exists to prevent.

**Decision:** preserve, and mark. The marker matters as much as the preservation —
without it, the next vocabulary sweep sees 79 Dutch properties and "fixes" them, and the
resulting breakage surfaces months later in an integration, not in a test.

### 2. GDPR is EU law, so `Verwerkingsactiviteit` is internationalised, not marked

The statute-marker rule exists for concepts with no international counterpart. A record
of processing activities is **GDPR Article 30** — the same obligation in every EU member
state, in twenty-four languages. The Dutch word is a translation, not a distinct concept.

**Decision:** `ProcessingActivity`, no statute marker. This is the same reasoning that
internationalised Woo: an obligation that exists everywhere gets the international word.

The codebase already agrees with this. `lib/Db/` holds **both** `Verwerkingsactiviteit.php`
and `ProcessingLogEntry.php`; `lib/Controller/` holds both `VerwerkingsactiviteitenController.php`
and `ProcessingLogController.php`. Two neighbouring concepts, one named in each language.
Where a codebase disagrees with itself, the English half is the intent — the same tell
openbuild's `title` fields gave.

⚠️ `ProcessingActivity` and `ProcessingLogEntry` are distinct concepts and must stay
distinct. The rename must not quietly merge a record-of-processing-activities with a
processing log.

### 3. `Archiefactiedatum` genuinely is NL-only, so it gets the marker

The Archiefwet's archival action date, with its selectielijst and MDTO machinery, has no
clean international counterpart — retention scheduling exists everywhere, but this
specific statutory date does not.

**Decision:** `ArchivalActionDateCalculator` **plus** a marker recording the Dutch
archival statute. This is the contrast case that makes decision 2 legible: GDPR is
everywhere and loses its Dutch; the Archiefwet is not and keeps its statutory identity
as data.

### 4. The MDTO generator renames its methods and keeps its elements

`addNaam()` calls `addTextElement(name: 'naam', …)`. The method name is ours; the string
`'naam'` is MDTO's.

**Decision:** rename the methods, preserve the strings. This leaves `addName()` writing
`<naam>`, which reads slightly oddly, and that is the honest cost of the fleet rule
rather than a reason to exempt the file. Recording it here means the next reviewer sees
a decision instead of an inconsistency.

### 5. `ZaaktypeAuthorizationService` is deferred to procest

Its own docblock says it maps "a ZGW Autorisaties API `autorisatie` record (zaaktype +
…)", and the feature is called `rbac-zaaktype`. So `zaaktype` here is the ZGW wire
resource name, which argues for keeping it — but procest is about to rename its `Zaak`
family to `Case`, and if that rename extends to type names, this service should follow.

**Decision:** do not touch it in this change. Guessing either way is silent: renaming a
wire-facing service misnames what it handles, and keeping it leaves the foundation app
inconsistent with procest. Recorded as an open question, not resolved by preference.

## Risks / Trade-offs

- **A renamed class breaks a consuming app's routing entirely** → one `extends` on a
  class that no longer resolves makes the router 500 every route in that app, because it
  reflects every controller. Only the class header is fatal, which means the check must
  cover headers and not just call sites. Mitigated by searching every consuming app
  before landing.
- **A future sweep renames the mock registers** → mitigated by decision 1's marker, which
  is the entire reason the marker exists.
- **`ProcessingActivity` is merged with `ProcessingLog`** → two distinct GDPR-adjacent
  concepts collapse. Mitigated by an explicit collision check in the tasks.
- **A re-measurement double-counts** → `custom_apps/` is gitignored, 4.5 GB, and contains
  identical copies of every other app's registers, including openregister's own. Any
  scan that walks it reports other apps' Dutch as openregister's. Mitigated by an
  explicit exclusion in the acceptance criteria.

## Migration Plan

1. Mark the five mock registers. No renaming.
2. Rename the `Verwerkingsactiviteit` family, with the `ProcessingLog` collision check.
3. Rename `ArchiefactiedatumCalculator` and its `brondatum` methods; attach the marker.
4. Rename `BrpPersoonProvider` and the remaining Dutch methods; preserve MDTO strings.
5. Search every consuming app for the old class names; migrate references in the same window.
6. `l10n/nl.json`, `check-l10n`, gates.

**Rollback:** steps 1–4 revert cleanly. Step 5 does not — once a consuming app has moved
to the new class name, reverting openregister breaks that app. The two move together.

## Open Questions

- Does `ZaaktypeAuthorizationService` follow procest's `Zaak` → `Case` rename, or does it
  keep the ZGW wire term? Blocked on procest.
- Does OpenRegister tolerate an unknown `x-` marker key on a schema without dropping it
  on import? The whole marking strategy in decision 1 rests on this, and it is
  **unverified** — a schema that fails import vanishes from the register, logged rather
  than raised. This must be tested before task 1 runs, not after.
