# Archival conformance: what openregister actually implements, measured against the standards

## Why this audit exists

openregister carries a substantial archival implementation — a TMLO field set, an
Archiefwet-shaped lifecycle, a selectielijst lookup, MDTO XML generation and an
e-Depot/SIP transfer path — spread over eleven openspec capabilities. Nobody has
written down **which parts of which standard it actually satisfies**, so the
honest answer to "are we MDTO compliant" has been unavailable to everyone who
asked.

This is that answer, measured against the code rather than against intent.

It also has a proximate cause. `@self._retention` was introduced as *the one
abstract archival answer any app can ask an object for*. Auditing it against the
standards showed it is currently blind to the single richest source of archival
metadata in the system — see gap **A1**, which is the author's own defect.

## Scope and one honest limit

**Read directly:** MDTO (published by the Nationaal Archief at `mdto.nl`),
the Archiefwet lifecycle, the ZGW `brondatumArchiefprocedure` vocabulary, and the
VNG selectielijst structure. All public.

⚠️ **ISO 15489-1, ISO 23081 and ISO 14721 (OAIS) texts are paywalled and I have
not read them.** Every ISO row below is measured against those standards'
*publicly documented structure* — the concepts and their names — and says so.
Where a finding depends on clause-level wording, it is marked `NEEDS-ISO-TEXT`
rather than asserted. **Do not cite this document as an ISO compliance
statement.**

## The vocabulary decision that frames everything below

The abstract layer speaks **MDTO concepts in English field names**. MDTO
supersedes TMLO, so `archiefnominatie` is the superseded spelling of MDTO's
`waardering`; the abstract key is `appraisal`. dossiq is not live, so no
migration path is owed to stored data.

| MDTO (Dutch) | Abstract key (English) | Superseded TMLO spelling |
| --- | --- | --- |
| `waardering` | `appraisal` | `archiefnominatie` |
| `bewaartermijn` | `retentionPeriod` | `bewaarTermijn` |
| — (derived) | `disposalDate` | `archiefactiedatum` |
| — (lifecycle) | `recordState` | `archiefstatus` |
| `informatiecategorie` | `disposalCategory` | `vernietigingsCategorie` |
| `beperkingGebruik` | `useRestriction` | — (absent) |
| `dekkingInTijd` | `temporalCoverage` | — (absent) |
| `aggregatieniveau` | `aggregationLevel` | — (absent) |
| `archiefvormer` | `recordCreator` | — (app config only) |

## Findings

Ordered by what they cost, not by standard.

### A — The abstract layer is incomplete

**A1 · `_retention` never reads the TMLO block. `openregister`. AUTHOR'S OWN DEFECT.**

`ArchivalDecisionResolver::resolve()` merges four sources: the stored `retention`
block, the schema's `x-openregister-archival` evaluation, the legal hold, and the
record's own ZGW archival properties.

It does **not** read `@self.tmlo` — the column `ObjectEntity` declares, that
`TmloService` populates and validates with a transition matrix, and that
`MdtoXmlGenerator` exports from. An object whose archival metadata lives only in
`tmlo` resolves to **no decision at all**, so `_retention` is absent and every
consumer reads silence.

That is the exact failure `_retention` was built to end, reproduced one level up.

**A2 · The Archiefwet lifecycle is invisible in the abstract layer.**

`TmloService` models `actief → semi_statisch → overgebracht | vernietigd` with a
`VALID_TRANSITIONS` matrix, and treats `overgebracht` and `vernietigd` as
immutable. This is recognisably the Archiefwet lifecycle and it is the single
most consequential archival fact about a record — whether it has been transferred
to an e-Depot or destroyed.

`_retention` exposes `status` from `retention.archiefstatus` only. A record whose
state lives in `tmlo.archiefstatus` reports no state.

**A3 · No `useRestriction`, `temporalCoverage` or `aggregationLevel` anywhere.**

Measured: `beperkingGebruik`, `dekkingInTijd`, `aggregatieniveau` and
`openbaarheid` appear in **zero** PHP files. `beperkingGebruik` in particular is
how MDTO carries a WOO/AVG access restriction; without it the export cannot say
that a record is restricted, and a receiving e-Depot cannot enforce it.

**A4 · `archiefstatus` carries two different vocabularies under one name.**

Found while implementing A1, not during the read-through.

`RetentionService::applyArchivalMetadata()` writes
`retention.archiefstatus = 'nog_te_archiveren'`. `TmloService` writes
`tmlo.archiefstatus` from `VALID_ARCHIEFSTATUS` — `actief`, `semi_statisch`,
`overgebracht`, `vernietigd` — and `nog_te_archiveren` is not among them. The
TMLO validator would reject the value the retention service writes, into a field
of the same name.

So `archiefstatus` means one of two things depending on which block it sits in,
and nothing in the code says which.

The resolver maps `nog_te_archiveren` onto `active` so the abstract answer is
coherent — a record "still to be archived" is live and not yet transferred. That
makes `_retention` usable.

**RESOLVED.** `retention.archiefstatus` now carries the Archiefwet lifecycle in
English — `active`, `semi_static`, `transferred`, `destroyed` — defined once in
`lib/Service/Archival/RecordState.php` and shared with the abstract layer.
`RetentionService`, `EdepotTransferService` and `DestructionExecutionJob` all
write from it.

Reads accept the old spellings and there is no migration, deliberately. A guard
that stopped recognising `overgebracht` would unlock every record an existing
install had already handed to an e-Depot, and a destruction sweep that stopped
recognising `nog_te_archiveren` would silently skip every pre-existing record —
the direction that keeps personal data past its lawful term. So each state
carries an alias list holding its English name and the Dutch spellings it
replaces, and every comparison goes through that list. Both directions are
mutation-checked: dropping the Dutch aliases reddens the immutability tests, and
widening one alias list to swallow the live states reddens the mutable tests.

`tmlo.archiefstatus` KEEPS ITS DUTCH SPELLINGS. It is its own block with its own
transition matrix and its own MDTO export mapping, and moving it is a separate
change. The two writers no longer disagree about what `retention.archiefstatus`
means, which was the defect; the abstract layer continues to read both.

### B — Selectielijst provenance is not reconstructable

**B1 · The selectielijst VERSION is never recorded.**

`RetentionService::applyArchivalMetadata()` stores `selectielijstBron` — a name —
and the `classification` category. It does not store which **version** of that
list was applied.

The same category carries different retention periods across selectielijst
revisions. Without the version, a disposal decision taken today cannot be
justified in five years: you can say *which list* but not *which list as it stood
when the decision was made*. That is precisely the provenance an audit asks for.

**B2 · The lookup silently yields nothing when unconfigured.**

`lookupSelectielijstEntry()` returns `null` when `selectielijstRegister` or
`selectielijstSchema` is unset, and the caller falls through to the schema's
`defaultNominatie` (`nog_niet_bepaald`). An instance that believes it is applying
a selectielijst and has simply not configured one gets a plausible-looking
default with no signal. The resulting `basis` says `schema`, which is true but
does not say *"and the selectielijst you expected was never consulted"*.

### C — ZGW derivation is a third implemented, and the gap computes a wrong date

**C1 · Three of nine `afleidingswijzen`, and the other six silently date from NOW.**

`VALID_AFLEIDINGSWIJZEN = ['afgehandeld', 'eigenschap', 'termijn']`.

ZGW's `brondatumArchiefprocedure` defines nine — `afgehandeld`,
`ander_datumkenmerk`, `eigenschap`, `gerelateerde_zaak`, `hoofdzaak`,
`ingangsdatum_besluit`, `termijn`, `vervaldatum_besluit`, `zaakobject`.

> ⚠️ **Provenance.** That enumeration is from the ZGW ZTC specification, not from
> this repository — only `afgehandeld` and `termijn` appear anywhere in the
> fleet. Confirm against the ZTC version in use before acting on the count.

The failure mode is worse than "unsupported". Traced through the code:

1. `determineBrondatum()` ends `default: return null` — the six unhandled methods
   all land there.
2. `calculateArchiveActionDate()` then does
   `if ($brondatum === null) { $brondatum = new DateTime(); }`.
3. The disposal date becomes **now plus the retention period**.

So a permit whose retention must run from `ingangsdatum_besluit` instead runs
from *the moment the calculation happened*, and nothing says so. A record
migrated or recalculated years after its decision gets a disposal date years too
late — the direction that keeps personal data beyond its lawful term.

**C2 · `VALID_AFLEIDINGSWIJZEN` is declared and never used.**

Measured: the constant has exactly one occurrence in the file — its own
declaration. Nothing validates against it. A schema configured with
`gerelateerde_zaak` is not rejected, not warned about, and not logged; it simply
takes the path in C1.

A validation list that validates nothing is the same defect class as an
authorisation method that is never called: the guard appears to exist.

**C3 · The fallback comment does not describe the fallback.**

```php
// If no brondatum can be determined, use creation date as fallback.
$brondatum = new DateTime();
```

`new DateTime()` is *now*, not the object's creation date. The object's real
`created` timestamp is on the entity and is what the comment intends. As written,
two identical records processed a year apart get disposal dates a year apart.

### D — MDTO export covers a real subset, and does not say which

**D1 · Element coverage.**

`MdtoXmlGenerator::generate()` emits `informatieobject` with `identificatie`
(`identificatieKenmerk` + `identificatieBron`), `naam`, `waardering`,
`bewaartermijn`, `informatiecategorie`, `archiefvormer` (`verwijzingNaam` +
`verwijzingIdentificatie`), optional `toelichting`, and `bestand` with
`checksumAlgoritme` + `checksumWaarde`.

Credit where due: it uses MDTO's own `waardering`, not TMLO's
`archiefnominatie`, and the checksum block is right.

Absent: `aggregatieniveau`, `beperkingGebruik`, `dekkingInTijd`, `event`,
`betrokkene`, `classificatie`, and the `isOnderdeelVan` / `bevatOnderdeel`
relation elements that carry aggregation structure.

**D2 · Required-field validation is narrower than MDTO.**

`validateRequiredFields()` demands four things: a uuid, `archiefnominatie`,
`bewaartermijn`, and the `organisation_identifier` app setting. A document
passing that check can still be rejected by a receiving e-Depot for a missing
element MDTO requires. The generator's contract is "these four are present", not
"this is valid MDTO", and it does not say so.

### E — ISO alignment, structurally

`NEEDS-ISO-TEXT` on every clause-level claim.

**E1 · ISO 15489 (records management) — appraisal and disposition are present,
the disposition *authority* is thin.** Appraisal (`waardering`), retention period
and a disposition action all exist. What is weak is the authority behind the
decision: see B1. A retention schedule you cannot version is not a disposition
authority you can defend.

**E2 · ISO 23081 (records metadata) — event history exists but is not archival
metadata.** `AuditTrail` records action, actor, timestamp and a hash chain, which
is materially what ISO 23081 asks of event history, and it is arguably stronger
than most implementations because it is chained. It is not linked into the
archival metadata or exported as MDTO `event`. The data is there; the connection
is not.

**E3 · ISO 14721 (OAIS) — SIP exists, AIP and DIP do not.** `SipPackageBuilder`
and `EdepotTransferService` produce a submission package. There is no notion of
an archival package as held, nor of a dissemination package for retrieval. For an
app that *transfers to* an e-Depot rather than *being* one, that is defensible —
but it should be stated, because "OAIS compliant" would not be true.

## What this proposes

Split by cost. Only the first group is proposed for immediate implementation.

### Now — the abstract layer tells the truth

1. **A1** — `ArchivalDecisionResolver` reads `@self.tmlo` alongside the other four
   sources.
2. **A2** — `recordState` in `_retention`, resolved from the TMLO lifecycle, with
   `overgebracht` / `vernietigd` marked immutable so a consumer can grey an edit.
3. **B2** — when a selectielijst was expected and not consulted, say so in
   `basis` rather than reporting `schema`.
4. **C2** — wire `VALID_AFLEIDINGSWIJZEN` up, or delete it. A configured method
   the code cannot honour must refuse loudly; today it is silently mis-dated.
5. **C3** — date the fallback from the object's `created`, which is what the
   comment already claims, not from `new DateTime()`.
6. Vocabulary → **MDTO concepts, English keys**, per the table above.

### Next — provenance and derivation

7. ~~**B1** — record the selectielijst version alongside its name.~~ **DONE.**
   `selectionListVersion` and `selectionListConsultedAt` are written beside
   `selectielijstBron`, taken from the row's own `versie` where it declares
   one and from the stored entry's `@self.version` where it does not. They
   surface abstractly as `sourceVersion` and `sourceConsultedAt`.
8. ~~**C1** — implement the six missing `afleidingswijzen`.~~ **DONE.** All nine
   are supported. Five of the six that were missing turned out to share one
   mechanic — follow a reference held on this record, read a date property off
   the record it points at — so openregister implements that mechanic once,
   configured by `sourceRelation` and `sourceRelationProperty`, and never
   learns what a zaak or a besluit is. `ander_datumkenmerk` reads a named date
   property on the record itself via `sourceDateProperty`.

   The six refuse to produce a disposal date at all when their source cannot be
   resolved, rather than falling back to the creation date. `afgehandeld` and
   `termijn` keep that fallback, because a record with no recorded closure was
   created and has been open since, which is defensible. `eigenschap` keeps it
   too, deliberately: it predates the change and existing installs may rely on
   it, so moving it is a separate decision.

### Found while implementing, and fixed here

**F1 · The pending-destruction-list exclusion was a no-op.**

`getObjectsOnPendingDestructionLists()` filtered on `object->status`.
`MagicSearchHandler` compares a filter key against the schema's OWN property
names and turns anything it does not recognise into `1 = 0` rather than
raising, so the query returned nothing on every run. The exclusion it feeds is
"objects already on a pending destruction list", so every sweep re-listed
objects that were already awaiting approval, and nothing said so. The key is
now `status`.

**F2 · `DestructionService::findEligibleObjects()` cannot return anything.
NOT FIXED HERE.**

It calls `MagicMapper::findAll()` with no `register`/`schema`, and `findAll()`
returns `[]` immediately in that case. It also filters on
`retention.archiefstatus`, a dotted JSON path the search handler does not
support outside the TMLO-specific branch, which would compile to `1 = 0` even
with the context supplied.

It has no production caller: `DestructionCheckJob` uses
`RetentionService::findEligibleForDestruction()`, which reads the objects table
directly and filters in PHP, and `TransferCheckJob::findEligibleObjects()` is
its own separate method that returns `[]` as a documented no-op. So nothing is
broken by it today. It is recorded here because it looks like a working query
and is not, and because the next person to wire it up will believe it works.

### Later — export completeness

9. **D2** — state the generator's contract honestly, then widen validation.
10. **D1/A3** — `useRestriction`, `aggregationLevel`, `temporalCoverage`, and
    `event` from the existing audit trail.

### Not proposed

**E3.** Becoming an OAIS archive is a different product. The transfer path is the
right scope; this document records that so the question stops being reopened.
