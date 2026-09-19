# Design: local-changes-to-app-shipped-configuration

## D-1: three versions, because two cannot answer the question

Comparing the live definition with the incoming one says what differs. It
does not say who differs. With the shipped baseline kept, every part falls
into one of four states: unchanged, changed locally only, changed upstream
only, changed on both sides. The first three have an obvious answer and only
the fourth needs a person. Without the baseline, all four look the same.

## D-2: the comparison is per part, not per file

A case type is a hundred properties, a lifecycle and a permission matrix.
Treating the whole descriptor as one unit means a single local label change
blocks an upstream fix somewhere else entirely. So the unit of comparison is
the smallest addressable part, and each part is resolved on its own.

## D-3: an unattended upgrade is the normal case

`occ upgrade` runs with nobody watching. So the default on conflict is to
keep the local value and report, never to apply and never to abort. An
upgrade that fails halfway through an app's registers is a worse outcome
than an upgrade that leaves four properties behind and says so in a report
somebody reads on Monday.

## D-4: reset is an act, not a repair

Going back to the shipped baseline is a deliberate decision with
consequences for stored objects. It is a route with an actor and an audit
entry, not something a repair step does because it found a difference.

## D-5: the baseline is versioned with the app that shipped it

The baseline records the app version it came from. Two upgrades apart, the
report still says which release the instance diverged from, which is what
makes the divergence answerable rather than merely visible.

## D-6: reuse analysis (ADR-012)

- The diff preview, the preserved local additions and the per-property
  conflict confirmation of `specs/schema-import`: reused, generalised from
  the standards dialects to the app-shipped descriptor.
- The import machinery invoked by repair steps: reused, with the guard
  inside it so no app writes its own.
- The audit trail: reused for the decisions and the reset.
- The effective-configuration explainer of `configuration-as-a-deployment`:
  reused as the place the divergence is reported beside the layers.
- No second importer and no second diff engine.
