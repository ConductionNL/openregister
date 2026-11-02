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
data), `stored` (the object before this write), `action` and `userId`. A
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

`SystemOperationContext` is the marker for writes the system makes: seeds,
example sets, configuration imports (`ImportHandler` runs inside it),
repair steps and background jobs. Apps already use it for their own internal
writes (pipelinq, planninq, humaniq, dossiq, launchpad and integriq do). A
create inside it may set any state, just as an update inside it may write
declared state today.

Everything else is a client and is checked. That includes a CSV or Excel
import run by a user. Since #5376, a bulk write into a lifecycle schema runs
the save events by default. Such an import is checked row by row: a row in a
later state is refused with `lifecycle-invalid-initial`, and the other rows
are written. An app that imports history in later states runs that import
inside `SystemOperationContext`.

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
