## ADDED Requirements

### Requirement: Replacement decisions MUST be planned on the immutable original text, never on progressively mutated text

The implementation MUST separate deciding *what* to replace from *applying* the replacement. Given the original text and the `needle => placeholder` substitution map, a planner MUST enumerate candidate matches against the **unmodified** original text and MUST NOT mutate that text while deciding. The result is a plan: a set of accepted, mutually non-overlapping ranges (`start`, `end`, `needle`, `placeholder`) plus the needles that matched nothing.

Sequential replacement over an ordered map is explicitly ruled out as the decision mechanism, because it makes every later decision depend on the text produced by earlier ones.

#### Scenario: Candidate enumeration does not observe earlier replacements

- **GIVEN** the text `Jan Jansen belde Jan Jansen`
- **AND** a substitution map containing `"Jan Jansen"` and `"Jansen"`
- **WHEN** the planner produces a plan
- **THEN** candidates for both needles are enumerated at every position where they occur in the original text
- **AND** the plan is identical whether or not any replacement has previously been applied to a copy of that text

### Requirement: Accepted ranges MUST be selected to maximise redacted coverage, deterministically

From the enumerated candidates the implementation MUST select the non-overlapping subset that maximises the total number of covered codepoints. No two accepted ranges may overlap.

Selection MUST be deterministic and independent of the order in which entities were recognised or inserted into the map. Candidates MUST be placed in a total order — start ascending, then span descending, then type rank ascending (structured identifiers before free-text types), then needle bytewise ascending — and when two selections yield equal total coverage, the one containing the earlier-ordered candidate MUST be chosen. Re-running anonymisation on unchanged input with an unchanged map MUST therefore produce byte-identical output.

Because a containing needle always covers strictly more codepoints than a needle inside it, this requirement subsumes longest-first ordering: the container always wins. Longest-first MUST NOT be the mechanism that provides the overlap guarantee, though a defensive length ordering of the map MAY be retained for callers that consume it directly.

#### Scenario: A containing entity beats the entity nested inside it

- **GIVEN** the text `Mail robert@rjzondervan.nl voor vragen`
- **AND** a map with `"rjzondervan"` → `[PERSOON: 1]` and `"robert@rjzondervan.nl"` → `[EMAIL: 2]`
- **WHEN** the plan is applied
- **THEN** the output contains `[EMAIL: 2]` and does NOT contain `[PERSOON: 1]`
- **AND** the output contains neither `rjzondervan` nor `robert@` nor `.nl` as residue of the email
- **AND** the result is the same when the two entities are supplied in the opposite order

#### Scenario: Two short entities are preferred over one long entity that overlaps both

- **GIVEN** candidate ranges where two non-overlapping needles cover 8 and 9 codepoints respectively
- **AND** a third needle covering 12 codepoints that overlaps both
- **WHEN** the planner selects accepted ranges
- **THEN** the two shorter needles are accepted (17 codepoints covered) and the longer one is rejected
- **AND** a purely longest-first strategy would instead have accepted the 12-codepoint needle and left the other two unmatched

#### Scenario: Selection is stable across recognition order

- **GIVEN** any document and substitution map
- **WHEN** anonymisation runs twice, the second time with the map's insertion order shuffled
- **THEN** both runs produce byte-identical output

### Requirement: Uncovered residue of a rejected overlapping candidate MUST be redacted

When a candidate is rejected because it overlaps an accepted range, any of its codepoints not covered by any accepted range MUST themselves be redacted, attributed to the rejected candidate's entity. A residue consisting solely of whitespace and/or a single punctuation codepoint MUST be dropped instead; any residue containing a letter or a decimal digit MUST be covered.

The needle MUST be reported as `partial` in the plan — matched, but not as one contiguous span — so callers can distinguish a clean redaction from a split one.

#### Scenario: A partially overlapping surname does not leak its tail

- **GIVEN** the text `Betreft: Jan de Vries-Bakker`
- **AND** a map with `"Jan de Vries"` → `[PERSOON: 1]` and `"Vries-Bakker"` → `[PERSOON: 2]`
- **WHEN** the plan is applied
- **THEN** the output contains neither `Vries` nor `Bakker`
- **AND** the output contains both `[PERSOON: 1]` and `[PERSOON: 2]`
- **AND** `"Vries-Bakker"` is reported as `partial`, not as unmatched

#### Scenario: Whitespace-only residue is not redacted

- **GIVEN** an accepted range whose rejected overlapping candidate leaves only a single space uncovered
- **WHEN** the plan is applied
- **THEN** no placeholder is emitted for that residue
- **AND** the space is preserved in the output

### Requirement: Matching MUST be case-insensitive and multibyte-correct, and verification MUST use identical semantics

Needle matching MUST be case-insensitive, folding both text and needle with a multibyte-aware lowercase operation. Byte-oriented case-insensitive comparison MUST NOT be used, because it mis-folds accented characters common in Dutch names.

Any post-application verification that reports residual entity text MUST use the **same** case folding and the **same** boundary policy as the matching step. A verification pass stricter than the matcher (for example a case-sensitive comparison against a case-insensitively applied replacement) is non-conforming, because it reports a document as fully anonymised when text the matcher was responsible for still remains.

#### Scenario: A differing-case occurrence is matched

- **GIVEN** the text `JAN JANSEN` and a needle `"Jan Jansen"`
- **WHEN** the plan is applied
- **THEN** the occurrence is replaced by the needle's placeholder

#### Scenario: A differing-case residual is reported

- **GIVEN** a document where an occurrence `JAN JANSEN` could not be reached by the writer
- **AND** the stored needle is `"Jan Jansen"`
- **WHEN** verification runs on the output
- **THEN** the needle is reported as residual
- **AND** the result is NOT reported as `complete`

### Requirement: Boundary policy MUST be resolved per entity type

Free-text entity types MUST match only at word boundaries; structured-identifier types MUST match literally. A boundary is the absence of an adjacent *word codepoint*, where a word codepoint is a Unicode letter, combining mark, decimal digit or underscore. The check MUST be Unicode-aware.

Default classification over the canonical types (`EntityRecognitionHandler::ENTITY_TYPE_*`):

- **Word-bounded:** `PERSON`, `ORGANIZATION`, `LOCATION`, `ADDRESS`, `DATE`
- **Literal:** `EMAIL`, `PHONE`, `IBAN`, `SSN`, `IP_ADDRESS`

A type outside the enumerated set MUST default to **literal**, erring toward redaction rather than toward leaving text in place.

#### Scenario: A short free-text name does not match inside an ordinary word

- **GIVEN** the text `In Januari sprak Jan met Bas in het Bassin`
- **AND** a map with `"Jan"` → `[PERSOON: 1]` and `"Bas"` → `[PERSOON: 2]`, both type `PERSON`
- **WHEN** the plan is applied
- **THEN** the standalone `Jan` and `Bas` are replaced
- **AND** `Januari` and `Bassin` are present in the output unmodified

#### Scenario: A structured identifier matches when adjacent to word characters

- **GIVEN** the text `IBAN:NL91ABNA0417164300x`
- **AND** a map with `"NL91ABNA0417164300"` → `[IBAN: 3]`, type `IBAN`
- **WHEN** the plan is applied
- **THEN** the IBAN is replaced despite word codepoints on both sides

### Requirement: Application MUST build the output in a single pass so placeholders are never rescanned

The writer MUST walk accepted ranges in ascending start order and construct the output by alternating original-text slices with placeholders. It MUST NOT apply replacements by repeatedly mutating a working copy of the text.

It MUST therefore be impossible for an emitted placeholder to be matched by any needle, including when the input already contains placeholder-shaped text from a previous anonymisation run.

#### Scenario: A needle that collides with placeholder text cannot match a placeholder

- **GIVEN** the text `Dossier 1 van Jan Jansen`
- **AND** a map with `"Jan Jansen"` → `[PERSOON: 1]` and a second entity whose text is `"1"`
- **WHEN** the plan is applied
- **THEN** the `1` inside the emitted `[PERSOON: 1]` is NOT replaced
- **AND** the standalone `1` in `Dossier 1` is replaced according to its own boundary policy

#### Scenario: Re-anonymising an already-anonymised document does not nest placeholders

- **GIVEN** an input document that already contains `[PERSOON: 1]`
- **WHEN** anonymisation runs again with the same map
- **THEN** no placeholder appears inside another placeholder in the output

### Requirement: Formats that store text in segments MUST be flattened before planning and scattered after

For any format that does not hold its text as one contiguous string, a format adapter MUST expose the document as an ordered list of mutable text segments in **document order**, together with each segment's offset in their concatenation. The planner MUST run once on that concatenation, so an entity split across segment boundaries is a single candidate.

When writing an accepted range back:

- The placeholder MUST be written entirely into the segment containing the range's **start**.
- Every subsequent segment the range overlaps MUST have its covered portion removed.
- A segment left empty MUST be retained as an empty string; the adapter MUST NOT remove or restructure the format's own nodes.

#### Scenario: An entity split across two docx runs is redacted

- **GIVEN** a docx where `Jan Jansen` is stored as two `<w:r>` runs, `Jan` and ` Jansen`
- **AND** a map with `"Jan Jansen"` → `[PERSOON: 1]`
- **WHEN** the document is anonymised
- **THEN** the output contains `[PERSOON: 1]` and contains neither `Jan` nor `Jansen`
- **AND** the placeholder resides in the run that held `Jan`
- **AND** the second run is present and empty

#### Scenario: Non-entity text and structure are preserved

- **GIVEN** a docx containing headers, a table, and a bulleted list, with entities in each
- **WHEN** the document is anonymised
- **THEN** every entity occurrence is replaced in all of those locations
- **AND** all text not covered by an accepted range is byte-identical to the input
- **AND** the header, table and list structure is unchanged

### Requirement: Every format MUST report unmatched and partially matched entities

For every supported format the implementation MUST populate the residual report — the needles the plan could not match at all, plus those reported `partial` — using the record shape and best-effort (not fail-closed) policy already defined by `pdf-anonymisation`. A format that cannot detect residuals MUST NOT report a result as `complete`.

A path that silently produces a partially anonymised document while reporting an empty residual list is non-conforming.

**Reporting MUST NOT block.** A detected residual MUST NOT cause the anonymisation to fail, throw, or withhold its output. The output file MUST still be produced and persisted, and the response MUST still be a success with `complete: false` and the residual list. This requirement exists to make the report a diagnostic the operator iterates on — add manual entities, adjust skip decisions, re-run — and explicitly NOT a gate. Extending detection to formats that previously reported nothing means more documents will report `complete: false` than before; that MUST surface as information, never as a refusal to deliver the file.

Turning residual detection into a blocking condition on any path is a product decision requiring explicit approval, and MUST NOT be introduced as a side effect of improving detection accuracy.

#### Scenario: A docx that could not be fully redacted reports residuals

- **GIVEN** a docx containing an entity occurrence the writer cannot reach
- **WHEN** anonymisation completes
- **THEN** the file is still produced and persisted
- **AND** `getLastResidualEntities()` returns a record for that entity with its `text`, `type` and `id`
- **AND** the response reports `complete: false` with a matching `residual_count`

#### Scenario: A fully redacted plain-text document reports no residuals

- **GIVEN** a plain-text document where every entity occurrence is matched and replaced
- **WHEN** anonymisation completes
- **THEN** `getLastResidualEntities()` returns an empty array
- **AND** the response reports `complete: true`

### Requirement: The planner MUST NOT alter entity identity, typing or placeholder text

The planner consumes the substitution map as given. It MUST NOT add, remove, split, merge or re-type entities, and MUST emit each placeholder string verbatim as supplied. Placeholder construction — the `[<TYPE>: <id>]` format, the scope-local numbering and the localized type label — remains owned by the calling handler.

Needles that are purely numeric MUST be handled correctly despite PHP coercing such array keys to `int`: every comparison, lookup and report path MUST cast explicitly to string.

#### Scenario: A numeric needle survives planning and reporting

- **GIVEN** a map whose needle is the digit string `"061234567"` (a spaceless phone number)
- **WHEN** the plan is produced and applied
- **THEN** the needle is matched and replaced with its placeholder
- **AND** if it cannot be matched it appears in the residual report as the string `"061234567"`, not as an integer
