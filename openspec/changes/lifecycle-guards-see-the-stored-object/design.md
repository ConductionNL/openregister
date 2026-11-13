# Design: lifecycle-guards-see-the-stored-object

## D-1: a second interface, not a new parameter

`LifecycleGuardInterface::check(array $object, string $action, string $userId)`
is implemented by several dozen guards across the fleet. Adding a parameter
to it, even an optional one, breaks every one of them: PHP refuses an
implementation whose signature takes fewer parameters than the interface.
So the stored object comes through a second interface,
`LifecycleTransitionGuardInterface`, which extends the first with
`checkTransition(LifecycleGuardContext $context)`.

`LifecycleValidationListener::runGuard()` calls `checkTransition()` when the
guard implements it and `check()` otherwise. A guard moves over by adding the
interface and the method. Nothing else changes for the apps that do not.

`LifecycleGuardContext` is a read-only value object: `object` (the incoming
data), `stored` (the entity being saved, as it is stored before this write),
`action` and `userId`, with `uuid()` and `storedData()` read from the stored
entity. The incoming data's `id` can name another object (humaniq#787), so a
guard that needs to look the object up uses `uuid()`, never the payload. A
context object, not more parameters, so a later field (the from and to state,
the schema) can be added without touching any guard.

## D-2: the create check lives where the initial state is filled in

`LifecycleInitialStateListener` already runs on `ObjectCreatingEvent` and
resolves the declared initial state, static or from a reference. It now
also compares a value the caller sets with that state, and refuses a
mismatch with `lifecycle-invalid-initial`. When a dynamic initial cannot be
resolved, a value the caller sets is refused: the check fails closed. A
create without a value still gets the initial state, and a schema without an
`initial` has no check.

The dynamic initial is now resolved on every create in such a schema, also
when the caller set a value. That is one reference read per create, the
price of comparing.

## D-3: who may create in another state

Ruben decided on 2 November 2026:

- **A schema declares the extra states a create may start in.** The key is
  `createStates`, a list beside `initial` in `x-openregister-lifecycle`. An app
  that creates objects in a later state on purpose lists that state:
  - a generator writing `free` slots;
  - a sign-up writing an `active` enrolment;
  - an app writing a `published` version.

  A create into any other state is refused, as before. The key lives inside
  the lifecycle block, which the schema stores whole, so it survives import,
  export and update without an allowlist change (tested).
- **Repairs, seeds and fixtures run as the system.** `SystemOperationContext`
  is the marker for writes the system makes: seeds, example sets,
  configuration imports (`ImportHandler` runs inside it), repair steps and
  background jobs. A create inside it may set any state.
- **An administrator can mark an import as historic.** `historic=true` on the
  register import lets each row keep its state. The controller checks for an
  administrator first and answers 403 to anybody else, so the flag is never
  honoured for a non-admin. An ordinary user's import is checked row by row.

  It does not run inside `SystemOperationContext`. ADR-099 rule 9 forbids a
  controller to elevate, and the boundary test (gate 97) refuses it. Ruben's
  intent is that rows keep their state, and a narrower marker does exactly
  that: `KeepLifecycleStatesScope` lifts only the initial-state check on
  creates. The import still runs as the administrator, so permissions, guards
  and validation apply as for any other write by them.

Since #5376, a bulk write into a lifecycle schema runs the save events by
default, so an ordinary import is checked per row: a row in an undeclared
later state is refused with `lifecycle-invalid-initial`, and the other rows
are written.

## D-3b: the order on a create that becomes an update

`ObjectCreatingEvent` runs before the insert claims its identifier. A
create whose identifier turns out to be taken, and which sets a later state,
is therefore refused by this check before #5395's update path can judge it
as an update. That needs a change inside `MagicMapper::insertObjectEntity()`,
which #5404 also changes, so it follows as its own change once #5404 is
merged.

Done: #5404 merged and the order landed as change
`create-over-taken-identifier-runs-update-path` (archived 2026-11-12).

## D-4: guards that should move to the stored object

Found with a grep of `$object['…']` reads in `lib/Lifecycle/*Guard.php`
under the local workspace checkouts. These guards read who or what an
object belongs to from the incoming payload, so the writer can set it:

| App (repo) | Guard | Fields read from the payload |
|---|---|---|
| humaniq (hrmq) | NoSelfApprovalGuard | employeeId, employeeUserId, proposedBy, userId |
| humaniq (hrmq) | ChangeApproverRoleGuard | requestedBy, managerUserId, approverRole, userId |
| humaniq (hrmq) | HandoffReleaseGuard | compiledBy |
| humaniq (hrmq) | RightToWorkGuard | employeeId |
| humaniq (hrmq) | LeaveBuySellApprovalGuard | employeeId |
| humaniq (hrmq) | PayrollRunApprovedGuard | payrollRunId |
| humaniq | the reopen guard in humaniq#787 | (per its review) |
| learniq (scholiq) | RegulationExemptionDecisionGuard, DataCorrectionDecisionGuard | requestedBy |
| learniq (scholiq) | ExternalTrainingVerificationGuard | submittedBy |
| learniq (scholiq) | LearnerOwnRowGuard, PortfolioSubmissionGuard, BsaDecisionGuard, SubjectChoiceConsentGuard, ConferenceSignupGuardianGuard, AttestationSigningGuard, RubricScoresCompletionGuard | learnerId |
| learniq (scholiq) | OsoDossierReviewGuard | learnerUserId |
| learniq (scholiq) | CohortMembershipGuard, SubmissionWindowGuard | learnerIds, assignmentId |
| buildiq (openbuild) | ApplicationVersionOwnerGuard | application |
| shillinq | PurchaseOrderApprovalGuard | approvalChain |

The local checkouts can lag behind GitHub. A lane that takes one of these
greps its app's current development first.
