# Design

## D-1 · Why the ordinal survives

The obvious reading of "use semver for flow versions" is to replace the
integer. It would be wrong here, and the cost is concrete:

- `oc_openregister_flows.version` and `oc_openregister_flow_versions.version`
  are both `integer NOT NULL`, and the second carries `UNIQUE (flow_uuid,
  version)`.
- `FlowRun.flowVersion` is an integer, and it is what a RUN PINS. A run
  resolves its graph through that pin for its whole life, including runs
  suspended for weeks on a human task.
- 28 call sites read or write it across seven files.

All of that would be migrated to buy a label. Worse, it would make the run
pin — the thing that guarantees a suspended run keeps walking the graph it
started on — depend on a derivation. A derivation with a bug would then not
merely mislabel a version; it would repoint a run.

So the ordinal stays as identity and ordering, and the semantic version is an
additional fact about a published version. The author sees semver, the engine
pins the ordinal, and neither has to be right for the other to work.

## D-2 · Why removal is the breaking rule

A consumer of a flow can depend on three things: that a step exists, that a
path connects, and that a step still reads the key it read. Every one of those
is broken by REMOVING something, and none by adding.

Changing a VALUE can also break a consumer — an assignee that no longer
resolves, a threshold that moves — but it is not detectable as breaking from
the graph alone. Guessing would produce majors nobody believes, which is worse
than a minor that was actually major, because a version people ignore carries
no information at all.

That gap is exactly what the author's override is for, and why the override
only goes upward.

## D-3 · Why the asymmetry in the override

The diff is evidence: it saw a node disappear. The author's optimism does not
change what the graph says, so a removal cannot be published as minor.

The author's knowledge, on the other hand, exceeds the diff — they know which
values consumers read. So they can add a major the diff did not find.

Evidence can only be added to, never argued down. Allowing both directions
would make the version mean "what somebody felt like", which is the state we
are leaving.

## D-4 · Why patch is always zero

Nothing in a graph distinguishes a fix from a feature. A derived patch level
would be a guess wearing three digits, and three digits look far more precise
than two. It stays `0` until something can honestly set it — an author saying
so, or a change class we can actually detect.

## D-5 · What the back-fill may and may not claim

The repair cannot know whether the third publish of a flow was breaking: the
graphs it would compare are precisely the ones it is being run to describe,
and older definition rows may have been pruned.

So it does not pretend. It stamps a flow's published versions in ordinal order
as `1.0.0`, `1.1.0`, … and records that they were BACK-FILLED rather than
derived. A version that says where it came from can be distrusted correctly; a
version that silently claims to be derived cannot.

🔴 It must not fail an upgrade over a version it cannot stamp. A flow whose
history is incomplete is already in that state; a repair that turns it into a
failed `occ upgrade` makes a reporting problem into an outage.

## Traps

**Do not derive at draft creation.** The ordinal is taken there, and the
instinct is to take the semver alongside it. There is nothing to compare yet —
the draft is a copy of the published graph — so every flow would be minor.

**Compare against the PUBLISHED graph, not the previous ordinal.** They are
usually the same and are not always: a deprecated version, or a draft opened
and abandoned, leaves a gap. The published graph is what a consumer is running.

**A config key removed from a node that ALSO disappeared is one change, not
two.** Counting both inflates the "what was removed" list the author reads, and
the list is the part that makes the verdict credible.

**An identical republish must not be refused.** Publishing the same graph twice
is idempotent and legitimate; making it an error would be a new failure mode
introduced by a labelling feature.
