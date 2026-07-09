## MODIFIED Requirements

### Requirement: The DI anonymise path MUST substitute each entity using a scope-local `[<TYPE>: <number>]` placeholder format

`DocumentProcessingHandler::anonymizeDocument` MUST substitute each detected entity occurrence with a placeholder of the form `[<TYPE>: <number>]`, where:

- `<TYPE>` is a **localized label** for the entity's `entityType` in the acting user's language (see the localization requirement below), e.g. `PERSON`/`PERSOON`, `ORGANIZATION`/`ORGANISATIE`.
- `<number>` is a **scope-local sequence number** — an integer assigned `1, 2, 3, …` by order of first appearance of the entity within the active anonymisation **scope**. It MUST NOT be the global `openregister_entities.id` (`e.id`).
- Whitespace MUST be exactly one space between the colon and the number.

The global entity id (`e.id`, looked up via `EntityRelationMapper::findEntityIdsByValueForFile($fileId)`) MUST be retained as the INTERNAL identity key — it is how the implementation knows that the same person detected in file A and file B is the same logical entity. The scope-local number is computed by TRANSLATING that internal `e.id` to a per-scope sequence number when the emitted placeholder is built; the catalogue, detection, and dedup behaviour are unchanged.

The emitted number MUST never be a cross-scope linking key. The hard rule is: the counter is never global, never carried across dossiers, never carried across separate publications.

#### Scenario: Placeholder uses a scope-local number, not the global entity id

- **GIVEN** an `openregister_entities` row with `id=7`, `value="Jan Jansen"`, `type="PERSON"`
- **AND** an anonymise run in which "Jan Jansen" is the first distinct entity to appear
- **WHEN** `DocumentProcessingHandler::anonymizeDocument` runs for that scope
- **THEN** every occurrence of "Jan Jansen" MUST be replaced with `[PERSON: 1]` (the scope-local number), NOT `[PERSON: 7]`
- **AND** the internal `e.id=7` MUST still be used to recognise that this is the same logical entity as any "Jan Jansen" detected elsewhere

#### Scenario: Sequence numbers are assigned by order of first appearance within the scope

- **GIVEN** a scope in which the first distinct entity to appear is "Jan Jansen" (`e.id=7`), the second is "Acme B.V." (`e.id=12`), the third is "Den Haag" (`e.id=3`)
- **WHEN** the anonymise pass runs
- **THEN** "Jan Jansen" MUST map to number `1`, "Acme B.V." to `2`, "Den Haag" to `3`
- **AND** the emitted placeholders MUST be `[PERSON: 1]`, `[ORGANIZATION: 2]`, `[LOCATION: 3]` (in the acting user's language) regardless of the underlying `e.id` values

### Requirement: The placeholder TYPE label MUST be localized to the acting user's language

The `<TYPE>` segment of the placeholder MUST be a label translated to the **acting user's UI language** via `IL10N`, drawn from the enumerated entity-type set (`PERSON`, `ORGANIZATION`, `LOCATION`, `EMAIL_ADDRESS`, `PHONE_NUMBER`, `DATE_TIME`, `IBAN_CODE`, and the other recognised types). The labels MUST be registered as translatable strings (`l10n/`), with Dutch translations provided. An entity type NOT in the enumerated set MUST fall back to its raw label (no translation, no error). The same localized label MUST be used for every occurrence within a run so the document stays internally consistent.

#### Scenario: Dutch instance emits Dutch type labels

- **GIVEN** the acting user's language is Dutch (`nl`)
- **AND** "Jan Jansen" (type `PERSON`) is the first distinct entity in scope
- **WHEN** `DocumentProcessingHandler::anonymizeDocument` runs
- **THEN** the placeholder MUST be `[PERSOON: 1]` (localized label + scope-local number), NOT `[PERSON: 1]`

#### Scenario: English (or default) instance emits English labels

- **GIVEN** the acting user's language is English (or a language with no translation for the type)
- **WHEN** the anonymise pass runs
- **THEN** the placeholder MUST use the English/base label, e.g. `[PERSON: 1]`

#### Scenario: Unknown entity type falls back to its raw label

- **GIVEN** a detected entity whose `entityType` is not in the enumerated translatable set (e.g. a backend-specific custom type)
- **WHEN** the placeholder is built
- **THEN** the raw `entityType` string MUST be used as the label unchanged, and no error is raised

### Requirement: Per-document scope is the default and assigns numbers within a single anonymise run

When no dossier scope signal is supplied, the anonymise pass MUST use **per-document** scope: the scope-local counter restarts for each file / each anonymise run, numbering the distinct entity ids encountered within that single file's run. Per-document scope MUST NOT consult or write any cross-file numbering store — the numbering is computed entirely within the single run.

#### Scenario: Per-document scope is the default

- **GIVEN** an anonymise call with no dossier scope signal
- **WHEN** the pass runs
- **THEN** the scope-local numbering MUST be computed within that one file's run only
- **AND** no persisted cross-file numbering store MUST be read or written

#### Scenario: The same person gets independent numbers in two separate per-document runs

- **GIVEN** "Jan Jansen" (`e.id=7`) appears as the first entity in file A and as the second entity in file B
- **AND** each file is anonymised in its own per-document run
- **WHEN** both runs complete
- **THEN** file A's placeholder for Jan Jansen MAY be `[PERSON: 1]` and file B's MAY be `[PERSON: 2]`
- **AND** the number MUST NOT be the same across the two files merely because the underlying `e.id` is the same

### Requirement: Within a single document the numbering MUST be consistent

Within one document/run the same logical entity (same `e.id`) MUST always resolve to the same scope-local number on every occurrence, for readability. This MUST hold for all variants of one entity (full name, surname-only, etc.) — they share one number, as they already share one `e.id`.

#### Scenario: Same entity, same number throughout one document

- **GIVEN** "Jan Jansen" (`e.id=7`, variants `["Jansen", "Jan"]`) appears five times in one document
- **WHEN** the document is anonymised
- **THEN** every one of the five occurrences (regardless of which variant matched) MUST be replaced with the SAME placeholder `[PERSON: <n>]`

### Requirement: Per-dossier scope is opt-in and consistent across the folder's files

When the caller supplies a dossier scope signal (`scope="dossier"` plus a stable `dossierKey`), the anonymise pass MUST use **per-dossier** scope. A folder IS the dossier. The scope-local number for a given logical entity (`e.id`) MUST be CONSISTENT across ALL files belonging to that `dossierKey`: every per-file anonymise call within one dossier MUST yield the same number for the same entity, regardless of which file is processed or in what order. The counter MUST restart between dossiers (different `dossierKey`) and MUST never be global.

The per-dossier number MUST be derivable identically from any of the dossier's files (the design defines this as a deterministic recomputation over the dossier's stored entities under a fixed order — no cross-call stored counter). The numbering MUST NOT be carried across separate publications: a dossier is the disclosure unit.

#### Scenario: Same person gets the same number across files in one dossier

- **GIVEN** `scope="dossier"`, `dossierKey="D1"`, and "Jan Jansen" (`e.id=7`) appears in file A and file B, both under dossier D1
- **WHEN** file A is anonymised, then file B is anonymised (in either order)
- **THEN** file A's placeholder for Jan Jansen MUST be the dossier-local number (e.g. `[PERSON: 1]`)
- **AND** file B's placeholder for Jan Jansen MUST be the SAME dossier-local number (`[PERSON: 1]`), however the implementation derives it

#### Scenario: The counter restarts between dossiers

- **GIVEN** "Jan Jansen" (`e.id=7`) is number `1` in dossier `D1`
- **WHEN** a different dossier `D2` is anonymised in which Jan Jansen appears as its second distinct entity
- **THEN** Jan Jansen's placeholder in `D2` MUST reflect `D2`'s own sequence (e.g. `[PERSON: 2]`), independent of his `D1` number
- **AND** no number MUST be carried from `D1` into `D2`

#### Scenario: The number is never global and never crosses publications

- **GIVEN** any anonymise run, per-document or per-dossier
- **WHEN** the placeholder is emitted
- **THEN** the number MUST NOT be derived from a counter that spans more than the active scope (the single document, or the single dossier)
- **AND** there MUST be no code path that reuses a number across separate dossiers or separate publications

### Requirement: Scope-local numbering MUST be stable and reproducible within a fixed scope

Within a fixed scope **and a fixed output language** the numbering and labels MUST be idempotent: re-running the redaction on the same input with the same scope and the same acting-user language MUST produce the same placeholders, and therefore byte-identical output (the grondslagen-summary report's traceability invariant, restated for scope-local numbers + localized labels). For per-document scope, number stability follows from the deterministic order-of-first-appearance assignment over the same file content. For per-dossier scope, it follows from the deterministic recomputation over a FIXED dossier file+content set; if the dossier's set of files or extracted entities changes, the dossier is re-ranked on the next run (numbers are final once all dossier files are extracted).

Because the emitted number changes from the global `e.id` to a scope-local number AND the TYPE label changes with the acting user's language, output produced BEFORE this change — or under a different language — is NOT byte-identical to output produced AFTER it (or under another language) for the same file. This is the deliberate **BREAKING** change. Stability is guaranteed only within a fixed scope and fixed output language under the post-change format.

#### Scenario: Re-anonymising the same document is byte-identical

- **GIVEN** a file anonymised under per-document scope at time T₁
- **WHEN** the same file is re-anonymised under per-document scope at time T₂ with the same content
- **THEN** the output at T₂ MUST be byte-identical to the output at T₁
- **AND** every placeholder MUST carry the same scope-local number it had at T₁

#### Scenario: Re-anonymising a file within an unchanged dossier reproduces the numbers

- **GIVEN** file A under dossier `D1` was anonymised, with Jan Jansen as dossier-local number `1`
- **WHEN** file A is re-anonymised under `scope="dossier"`, `dossierKey="D1"`, with the dossier's files and extracted entities unchanged
- **THEN** Jan Jansen MUST again receive `[PERSON: 1]` (recomputed identically over the unchanged dossier)
- **AND** the output MUST be byte-identical to the prior run

#### Scenario: Post-change numbers differ from pre-change global-id numbers (BREAKING)

- **GIVEN** a file whose pre-change anonymised output used the global entity id (e.g. `[PERSON: 7]`)
- **WHEN** the file is anonymised after this change under any scope
- **THEN** the placeholder number MUST be the scope-local number (e.g. `[PERSON: 1]`), which MAY differ from the pre-change global id
- **AND** this difference is expected and MUST NOT be treated as a regression
