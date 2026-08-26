# structured-tool-grants Specification

**Status**: planned
**Scope**: openregister (the ADR-099 §5 capability grammar); hermiq owns the rest of this capability
**OpenSpec changes**:
- `structured-tool-grants`

## Purpose

An agent's tool grants are stored as a **structure** — `app → subject → action → tool id` — rather
than as a list of opaque strings every consumer has to take apart again.

`Agent.tools` was a `string[]` (ADR-035 Decision 4 froze that shape), so the only way to answer
"which app is this grant for?" or "is this a read?" was to split the id and guess. Three spellings
are in live use — `pipelinq.lead.search` (app.subject.action), `hermiq.listFiles` (app plus a
camelCase verb-first name) and `list_registers` (a bare snake-case verb-first name) — and every
consumer needed its own rule for all three.

That guessing was measurably wrong. On the live catalogue, **35 of the 87 tools that declare no
taxonomy parsed incorrectly** in the grant matrix: five inverted (`cms_create_page` yielded the
subject "create") and thirty lost their verb entirely, rendering the whole OpenRegister core as
thirty one-off rows. The parser held the bug, but the parser only existed because the stored shape
threw the structure away and asked each reader to reconstruct it.

ADR-095 supersedes the `string[]` half of ADR-035 Decision 4. This capability specifies the stored
shape, the compatibility boundary, and the one rule that keeps a stored grant honest: **the tool id
is stored, not derived.**

## Where the structure lives, and why not in storage

🔴 **The structure is the domain model; the stored shape stays a list of grant strings.** Storing the
map was tried and is not expressible: `Agent.tools` is declared `type: array`, and OpenRegister
permits exactly **one** type per property. A union (`["array","object"]`) is rejected by its importer
— `Invalid type '["array","object"]' … must be one of: string, number, integer, boolean, array,
object, null, …` — and omitting `type` defaults the property to `string`, which is worse. Both were
tried against a live instance.

Until that changes, writing the map fails validation on **every** save
(`Property 'tools' should be type 'array or null' but is 'object'`), so the owner gets a 500 on a
save that changed nothing. Reads still accept either shape, so an agent written structured by an
earlier build keeps working.

## ADDED Requirements

<!--
  RELOCATED SUBSET — the canonical home for this capability is hermiq.

  ADR-099 §5 moved the tool-grant grammar (ToolGrantSet, ToolGrantCodec,
  ToolGrantResolver, ToolReachResolver, ToolGrantResolutionException) into
  OCA\OpenRegister\Service\Capability. The requirements below are the ones that
  code implements, copied VERBATIM from hermiq so their headings — and therefore
  every `@spec` anchor pointing at them — resolve unchanged.

  🔴 THIS FILE IS NOT THE WHOLE SPEC. 1 further requirement(s) live in
  hermiq's `openspec/specs/structured-tool-grants/spec.md` and are NOT duplicated here: they
  describe behaviour hermiq still owns — the `Agent.tools` binding, the approval
  gate, the oversight surface, the CLI transport. Read that file for them.

  WHY A COPY AT ALL. A `@spec` tag is dereferenced by gate-46 against the
  REPOSITORY it sits in, so a cross-repo reference is not expressible: an
  openregister class citing a hermiq spec resolves to nothing, which is the
  ~300-dead-tag shape this fleet already carries from archived changes. The
  duplication is therefore structural rather than an oversight — and it is
  bounded to exactly the requirements the moved code implements, which is why
  this file is a subset and not the original.
-->

### Requirement: Tool grants are a structure in the domain, and a list in storage

Grants MUST be modelled as a nested map keyed `app → subject → action`, whose leaf carries the
**tool id exactly as the catalogue publishes it**, together with any argument constraints that grant
carries. Consumers MUST read those coordinates rather than re-deriving them from an id.

Grants MUST be **persisted** as the list of grant strings that `Agent.tools`'s declared type allows.
The write path converges on that shape; the read path accepts both.

The tool id MUST be stored rather than recomputed from its coordinates. `hermiq.listFiles` sits at
coordinates `(hermiq, file, list)`; composing an id from those coordinates yields `hermiq.file.list`,
which is not a tool and never was. A reader that derives the id silently grants nothing.

A single action MUST be able to hold **more than one** entry, because two grants for the same tool
may differ only by their argument constraints. Collapsing them to one loses a constraint, and losing
a constraint widens a grant from one flow to every flow.

#### Scenario: a grant round-trips without losing its identity

- **GIVEN** an agent granted `hermiq.listFiles`, whose taxonomy is `(hermiq, file, list)`
- **WHEN** the grant is stored and read back
- **THEN** the tool id read back is `hermiq.listFiles`
- **AND** it is not `hermiq.file.list`

#### Scenario: the written shape is the one the schema declares

- **GIVEN** grants submitted in either shape
- **WHEN** they are persisted
- **THEN** the stored value is a list of grant strings
- **AND** the save succeeds rather than failing OpenRegister validation

#### Scenario: two constrained grants for one tool both survive

- **GIVEN** an agent granted `pipelinq.lead.search?status=open` and `pipelinq.lead.search?status=won`
- **WHEN** the grants are stored and read back
- **THEN** both entries are present under that action, each with its own constraint
- **AND** neither constraint has been dropped or merged into the other

### Requirement: The legacy string shape is still accepted, and is not silently rewritten

A stored value that is a **list** MUST be read as the legacy `string[]` grammar and honoured as-is.
A stored value that is a **map** MUST be read as the structured shape. Shape detection MUST use
`array_is_list()`, not the type of a single key: a structured map whose first key happens to be
numeric is a map, and reading it as a list yields **no tools at all**.

A legacy list MUST be passed through rather than round-tripped into the structured shape on read,
because regrouping by app reorders the grants and `baseToolIds()` promises order.

#### Scenario: a legacy list is honoured unchanged

- **GIVEN** an agent whose stored `tools` is `["openregister.contact.read", "hermiq.listFiles"]`
- **WHEN** the agent's grants are resolved
- **THEN** both tools resolve exactly as before the structured shape existed
- **AND** the stored value is not rewritten as a side effect of reading it

#### Scenario: a structured map with a numeric key is not mistaken for a list

- **GIVEN** a structured grant map that contains a numeric key
- **WHEN** the shape is detected
- **THEN** it is read as the structured shape
- **AND** the agent does not lose every one of its tools

### Requirement: The legacy grant grammar lives in exactly one place

Parsing and formatting of the legacy grant string grammar — `{app}.{subject}.{action}`,
`{app}.{camelCaseName}`, snake-case `verb_subject`, the `.*` and `.*:write` wildcards, and
`?key=value&other=in:a,b` argument constraints — MUST live in a single codec. No consumer may
re-implement the split.

#### Scenario: consumers read coordinates rather than splitting ids

- **GIVEN** a consumer that needs the app, subject or action of a grant
- **WHEN** it reads that grant
- **THEN** it obtains the coordinates from the stored structure or the codec
- **AND** it does not split the tool id itself
