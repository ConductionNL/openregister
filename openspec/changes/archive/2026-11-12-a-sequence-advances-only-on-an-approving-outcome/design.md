# Design: a-sequence-advances-only-on-an-approving-outcome

No screen. The approver keeps using the task inbox and the task detail page.

## D-1: the default approving set is `approved` and `done`

The inventory decides the default. `done` is what every generic task screen sends: the task detail page, `CnTasksWidget` and the calendar write-back. Approval positions declare no outcomes, so an approver in the inbox has no other button. Leaving `done` out would stop every declared approval chain the moment this ships. `approved` is what migrated chains and filinq send.

`resolved`, `accepted` and every other word stay out. Nothing in the fleet completes a sequence position with them, and a narrower set is the safer side of an approval.

## D-2: a chain may declare its own set

`approvingOutcomes` on a chain in `x-openregister-approval-chains` is a list of strings. The compiler trims and lowercases each entry and drops empty ones. It writes the key only when the chain declares a usable list, so the template of every existing chain stays the same. A sequence reads the list off its frozen snapshot, never off the live schema: a schema edit cannot change what approves an approval that is already running. A declared list that leaves nothing usable falls back to the default.

A signing chain declares `["approved"]`, so a signer who only ticks a task done has not signed.

## D-3: rejecting wins

The rejecting vocabulary is checked first. A declared approving list that names `rejected` cannot turn a rejection into an approval.

## D-4: neither approving nor rejecting ends the sequence as `undecided`

A completed position with another outcome is a decision nobody can read. Advancing would invent an approval. Rejecting would invent a refusal and its mandatory comment. Keeping the sequence running would leave it stuck: the position is terminal and is never offered again.

So the sequence closes with status `undecided` and outcome `undecided`. The remaining tasks are terminated through the same path a rejection uses, with a reason that names the position and the outcome. `TaskSequenceUndecidedEvent` carries the sequence and the task, so an app can tell its user to start again.

`undecided` is terminal. The gate treats it like `rejected` and `terminated`: the next attempt opens a new sequence and the undecided one stays readable. Readers that check for `completed` (opencatalogi) refuse it. buildiq's status mapping reads it as `none`.

## D-5: what stays

- A task that ends without completing (cancelled, terminated, disabled) still terminates the sequence.
- The `consume` verb keeps its own rule (completed and not rejecting). It guards a different thing, a decision used once, and is out of scope here.
