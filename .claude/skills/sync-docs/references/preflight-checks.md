# Pre-flight Metadata Checks

Run four checks in parallel before syncing:

## Check A — config.yaml rules alignment

Compare the `rules:` sections in `openspec/config.yaml` against `writing-docs.md` and `writing-specs.md` (in `{GITHUB_REPO}/docs/claude/`). Look for:
- Rules present in `config.yaml` (e.g. under `rules: specs:`, `rules: proposal:`) that contradict or are not reflected in the relevant writing doc
- Writing conventions documented in `writing-specs.md` or `writing-docs.md` that conflict with what `config.yaml` instructs Claude to do

## Check B — Sources of Truth accuracy

Read the Sources of Truth table in `{GITHUB_REPO}/docs/claude/writing-docs.md` and verify each entry against the actual project:
- Sources listed that don't exist (file was moved, renamed, or never created)
- Important files that exist but aren't listed (e.g., a new ADR index or guide was added)

## Check C — writing-specs.md → schema alignment

Compare `writing-specs.md` (in `{GITHUB_REPO}/docs/claude/`) against the `specs` artifact in `openspec/schemas/conduction/schema.yaml` and `templates/spec.md`. The schema is the consumer of writing-specs.md — its instruction and template must stay consistent with the project's spec writing conventions. Look for:
- Scenario format in `templates/spec.md` doesn't match the GIVEN/WHEN/THEN format in `writing-specs.md`
- Required spec sections added to `writing-specs.md` not reflected in the instruction or template
- RFC 2119 keyword guidance in `writing-specs.md` that contradicts what the `specs` instruction tells Claude to do
- New delta operation guidance in `writing-specs.md` (ADDED/MODIFIED/REMOVED/RENAMED) that isn't covered in the template
- Examples in the `specs` instruction that use outdated patterns compared to `writing-specs.md`

Do NOT flag the reference to `writing-specs.md` itself as a gap — the instruction already defers to it. Only flag cases where the template or instruction actively contradicts or omits something from `writing-specs.md` that Claude needs to know at artifact creation time.

Report as: "writing-specs.md changed in these areas — schema may need updating."

## Check D — forked schema drift from upstream

1. Read `openspec/schemas/conduction/schema.yaml` and check for a `parent:` field.
2. If a `parent:` field is present, run `openspec schema which <parent-name>` to locate the upstream schema. Use the returned path to read the upstream `schema.yaml` and `templates/`.
3. If no `parent:` field, run `openspec schema which conduction` — if it returns a non-project source, use that path as the upstream.
4. If neither yields an upstream path, report: "N/A — no upstream found."

Compare the forked `schema.yaml` artifact instructions and `templates/` files against the upstream. Because `conduction` is a heavily customized fork, most structural differences are intentional — focus only on:
- New artifacts added to the upstream schema that don't exist in the fork
- Guidance or gotchas added to upstream instructions that the fork is missing entirely (not just differently worded)
- Template improvements in upstream that would apply to conduction without conflicting with its customizations

Do NOT flag differences that are intentional customizations (e.g., the Affected Projects section, GitHub Issues task format, cross-project dependency guidance). Only flag upstream changes the fork may genuinely want to incorporate.

Report as: "Upstream schema changed in these areas — review and merge if applicable."

## Reporting gaps

If gaps are found in any check, report them together:

```
⚠ Project metadata may be out of sync:

  config.yaml rules vs writing-docs.md / writing-specs.md:
    config.yaml says: "{rule}"
    writing-specs.md: {not mentioned / contradicts}

  Sources of Truth:
    Listed ".claude/openspec/config.yaml" — file path changed to ".claude/openspec/config.yaml"
    Found ".claude/openspec/architecture/adr-016-*.md" — not listed as a source of truth

Update before syncing? (Yes / No, sync anyway)
```

If user says Yes — update `writing-docs.md` first, then proceed with the requested sync target.
If user says No — proceed with the sync but note the gap in the final report.
