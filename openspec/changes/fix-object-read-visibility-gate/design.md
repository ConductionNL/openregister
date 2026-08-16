# Design note — how the first diagnosis went wrong

Phase 0.3 of the original `tasks.md` asked for the anomaly to be recorded here.
The anomaly turned out to be the load-bearing detail, so this note keeps both
the answer and the reasoning error that made it necessary.

## The anomaly, and its answer

**Observed:** setting `read: ['rbac-editors']` on schema 18 flipped
`rbac-editor` from 0 visible objects to 21 — even though the gate the first
proposal blamed asks for `create`, which no `read` rule can satisfy.

**Answer:** that gate never executed. Object listing is filtered in SQL by
`MagicRbacHandler::buildRbacConditionsSql(schema, action: 'read')`. The `read`
rule was consumed by the SQL gate, which is the only gate on that path. The
result was not surprising; the model of the code was wrong.

## Why the wrong conclusion looked well-evidenced

The first proposal was not built on a guess. It had a code reference, a comment
directly above the line stating the loop decides visibility, a measured
21-vs-0 table, and a doc page naming that function as the List enforcement
point. Every one of those was real. The conclusion was still wrong, because a
step was assumed rather than checked:

**Nobody asked whether the function had a caller.**

One `grep -rn filterObjectsForPermissions` across `lib/` answers it in seconds
and returns nothing but the definition. That check was never run, because the
function *looked* live: it is public, documented, `@spec`-annotated, covered by
a test, and described in the feature docs. Dead code that is well maintained is
indistinguishable from live code at a glance.

The 21-vs-0 measurement then reinforced the error. It was a true measurement of
a real effect, and it was compatible with the wrong model only because the
wrong model was never asked to predict it. When the proposal noticed the
mismatch, it recorded it as an "open question" and carried on — the mismatch
*was* the refutation.

## The rule this yields

> A code path is not established as live by reading it. Evidence that it is
> reached — a caller, a log line, a breakpoint, a deliberate break — is a
> separate fact and has to be gathered separately.

Corollary, and the one that actually cost the time here: **when a measurement
does not fit the model, that is the finding.** Filing it as an open question
next to the conclusion it contradicts preserves the conclusion at the expense
of the evidence.

## Artefacts to correct

- `docs/features/organisation-roles.md:670,673` — names `hasPermission()` and
  `filterObjectsForPermissions()` as the Read/List enforcement points. The live
  one is `MagicRbacHandler::buildRbacConditionsSql()`. This doc line is part of
  why the wrong function looked live and should be fixed with the code.
- Conduction/openbuild#76 — carries the superseded diagnosis in its comments;
  needs the correction before anyone acts on it.

## Cross-handler contract, as measured

| Authorization block | List (`MagicRbacHandler`) | `resolveReadGroupIds()` |
|---|---|---|
| absent / empty | open to all (`:1024` bypass) | broadcast (`:1610`) |
| non-empty, no `read` key | **owner-only** (`:1029`→`:1041`) | **broadcast** (`:1614`) |
| `read: ['public']` | anonymous, plus authenticated when `inheritFromPublic` | broadcast (`:1625`) |
| `read: ['authenticated']` | any logged-in caller (`:413`) | targeted list |
| `read: ['<group>']` | members of that group | members of that group |

Row 2 is the disagreement recorded as item 3 of the proposal. Rows 1, 3, 4 and
5 agree across both paths.
