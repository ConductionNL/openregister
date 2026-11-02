<?php

/**
 * The event listener registrations of OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Service\ApprovalChainAnnotationInstaller;
use OCA\OpenRegister\Service\Notification\NotificationsAnnotationInstaller;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Subscribes every OpenRegister listener to its events.
 *
 * Application::register() calls register() once. The registrations run in a
 * fixed order, and the order is part of the behaviour: listeners on the same
 * event run in the order they were registered.
 *
 * An event that only one component listens to is registered by that
 * component's registrar in Registrar\. An event with listeners from more than
 * one component stays here, in one ordered list. ListenerOrderTest pins the
 * order of every event.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A registrar names every listener
 *   and every event it subscribes to; that coupling is what it is for.
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)  Each method is one ordered block of
 *   registrations; cutting it further would scatter the order across more methods.
 */
final class ListenerRegistrar {

	/**
	 * Register all event listeners for the application, in order.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The component registrars are static, like this one; there is no instance to inject at registration time.
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	public static function register(IRegistrationContext $context): void {
		Registrar\AccessListenerRegistrar::register(context: $context);
		Registrar\ConfigurationListenerRegistrar::register(context: $context);
		Registrar\FlowsListenerRegistrar::register(context: $context);
		Registrar\McpListenerRegistrar::register(context: $context);
		Registrar\NotificationsListenerRegistrar::register(context: $context);
		Registrar\WorkListenerRegistrar::register(context: $context);
		self::registerFileAndPlatformListeners(context: $context);
		self::registerFlowAndTaskListeners(context: $context);
		self::registerSaveRuleListeners(context: $context);
		self::registerSideEffectListeners(context: $context);
		self::registerDeliveryListeners(context: $context);
		self::registerDraftListeners(context: $context);
	}//end register()

	/**
	 * Hook the platform: file changes and writes, sign-in, discovery events, OCM and Context Chat.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 */
	private static function registerFileAndPlatformListeners(IRegistrationContext $context): void {
		// The organisation tree guard. A party's parent is checked on the SAVE,
		// not in whatever wrote the object: a check that lives in one caller is
		// a check the next caller does not have, and a written cycle reaches
		// every later reader as a parent chain with no end.
		$context->registerEventListener(
			\OCA\OpenRegister\Event\ObjectCreatingEvent::class,
			\OCA\OpenRegister\Listener\PartyTreeGuardListener::class
		);
		$context->registerEventListener(
			\OCA\OpenRegister\Event\ObjectUpdatingEvent::class,
			\OCA\OpenRegister\Listener\PartyTreeGuardListener::class
		);
	}//end registerFileAndPlatformListeners()

	/**
	 * Text extraction, lifecycle transitions, flow triggers, tasks, timers and cases.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 */
	private static function registerFlowAndTaskListeners(IRegistrationContext $context): void {
		// ObjectChangeListener for automatic object text extraction.
		$context->registerEventListener(ObjectCreatedEvent::class, ObjectChangeListener::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, ObjectChangeListener::class);

		// Automatic lifecycle transitions: note the written object so the
		// request-scoped pass can decide its `autoWhen` rules once the write is
		// finished. The listener records and nothing else; applying here would
		// be lost to the second write a file-bearing save makes. NOT wired to
		// ObjectTransitionedEvent: a named transition's save already dispatched
		// ObjectUpdatedEvent, so both would record it twice.
		$context->registerEventListener(ObjectCreatedEvent::class, AutoTransitionRecordListener::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, AutoTransitionRecordListener::class);

		// Object-lifecycle flow triggers: queue a run for every flow wired to a
		// lifecycle event. Create / update / delete plus lock / unlock / revert /
		// state-change — the last four were declared in the event catalog but had
		// no listener firing them until now, so a flow could select them and never
		// run.
		$context->registerEventListener(ObjectCreatedEvent::class, \OCA\OpenRegister\Listener\FlowTriggerListener::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, \OCA\OpenRegister\Listener\FlowTriggerListener::class);
		$context->registerEventListener(ObjectDeletedEvent::class, \OCA\OpenRegister\Listener\FlowTriggerListener::class);
		$context->registerEventListener(ObjectTransitionedEvent::class, \OCA\OpenRegister\Listener\FlowTriggerListener::class);

		// Flow preflight — refuse a flow document naming a step type this
		// instance cannot run. Registered FIRST on both events so an unrunnable
		// flow is rejected before any other listener does work on the way in.
		// The registry has always refused an unknown type, but only at dispatch,
		// mid-run, after earlier steps had already made changes that do not roll
		// back (or#2247: a flow named `openregister.explode` against an instance
		// that predated it, and nothing noticed until someone checked by hand).
		$context->registerEventListener(ObjectCreatingEvent::class, FlowNodePreflightListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, FlowNodePreflightListener::class);

		// Task cancellation propagation (flow-task-entity, design D-8): a run
		// persisted in a terminal status terminates its open tasks, with the
		// reason recorded. The event fires from FlowRunMapper::update() — the
		// one choke point all terminal writes pass — and may fire repeatedly;
		// the listener is idempotent, and a task with run_uuid null is
		// structurally out of its reach.
		$context->registerEventListener(
			\OCA\OpenRegister\Event\FlowRunTerminalEvent::class,
			\OCA\OpenRegister\Listener\TaskRunTerminalListener::class
		);

		// Release layer 1 of run-scoped object locking: a run that holds object
		// locks releases every one of them when it ends, on ANY of the four
		// terminal statuses. Hooked here rather than in a node because a run
		// that failed or was reaped never reaches another step, and that is
		// exactly the run whose lock most needs releasing. Idempotent, and it
		// never rethrows: the dispatch happens inside the run's own terminal
		// write.
		$context->registerEventListener(
			\OCA\OpenRegister\Event\FlowRunTerminalEvent::class,
			\OCA\OpenRegister\Listener\FlowRunLockReleaseListener::class
		);

// The other direction (flow-user-task-node): a task the graph raised
		// reached a terminal state, so its suspended run is woken and, per the
		// node's `advance` budget, continued in-request. Fires AFTER the task's
		// own transaction committed (the listener skips uncommitted dispatches);
		// a failure here costs latency, never the completion.
		$context->registerEventListener(
			\OCA\OpenRegister\Event\TaskTerminalEvent::class,
			\OCA\OpenRegister\Listener\UserTaskTerminalListener::class
		);

		// Business-timer cancellation propagation (flow-business-timers, design
		// D-9): a terminal task (TaskMapper, both write paths) or a terminal run
		// (FlowRunMapper) cancels its open timers INSIDE the write that made the
		// subject terminal, so no escalation goes out about finished work. The
		// listener is idempotent and never deletes.
		$context->registerEventListener(
			\OCA\OpenRegister\Event\TaskTerminalEvent::class,
			\OCA\OpenRegister\Listener\FlowTimerSubjectTerminalListener::class
		);
		$context->registerEventListener(
			\OCA\OpenRegister\Event\FlowRunTerminalEvent::class,
			\OCA\OpenRegister\Listener\FlowTimerSubjectTerminalListener::class
		);

		// The portal reminder (flow-portal-task, design D-8): a preBreach rung
		// of flow-business-timers on an EXTERNAL task becomes a reminder
		// delivery request through the portal seam; a slaBreached rung stays
		// inward. Registered by the event's NAME because the timers change is
		// built in parallel and its event class may not be on this branch yet;
		// the listener is duck-typed against the event's published surface, so
		// the two merge in either order.
		$context->registerEventListener(
			\OCA\OpenRegister\Listener\PortalTaskReminderListener::EVENT_CLASS,
			\OCA\OpenRegister\Listener\PortalTaskReminderListener::class
		);

		// Task projections (flow-task-inbox-projections): a committed
		// transition becomes a declarative notification and a VTODO in the
		// assignee's calendar. Both run AFTER the commit and neither can fail
		// the transition (design D-8). Withdrawal runs before delivery, so the
		// notification listener is registered first.
		$context->registerEventListener(
			\OCA\OpenRegister\Event\TaskTransitionedEvent::class,
			\OCA\OpenRegister\Listener\TaskNotificationListener::class
		);
		$context->registerEventListener(
			\OCA\OpenRegister\Event\TaskTransitionedEvent::class,
			\OCA\OpenRegister\Listener\TaskCalendarProjectionListener::class
		);

		// Dismiss-on-terminality, on the same terminal-task event the
		// user-task node listens to: no approve button and no open VTODO
		// survives a terminal task, however it became terminal. Idempotent
		// beside the transition listeners above: a second withdrawal finds
		// nothing to withdraw and a second render finds nothing changed.
		$context->registerEventListener(
			\OCA\OpenRegister\Event\TaskTerminalEvent::class,
			\OCA\OpenRegister\Listener\TaskTerminalProjectionListener::class
		);

		// The case layer (flow-cmmn-case-semantics). A realisation ending (a task
		// or a run) drives its plan item; an object change may satisfy a sentry;
		// and a plan item reaching a terminal state is a catalog event fired
		// against the anchoring object. Nothing under Service\Flow depends on
		// Service\Case: these listeners are the only coupling, and it points one
		// way. TaskTerminalEvent is dispatched by TaskService after the
		// terminal transition commits (flow-user-task-node); the same
		// reconciliation also runs on every case-plan evaluation, so a missed
		// event costs latency, never correctness.
		$context->registerEventListener(
			\OCA\OpenRegister\Event\TaskTerminalEvent::class,
			\OCA\OpenRegister\Listener\CaseTaskTerminalListener::class
		);

		// A closed task releases the tasks that waited on it
		// (a-task-may-wait-on-another-task D-4): a `released` audit entry and a
		// fresh announcement per open dependant. Post-event work on the
		// committed dispatch; the blocked flag itself is derived on read.
		$context->registerEventListener(
			\OCA\OpenRegister\Event\TaskTerminalEvent::class,
			\OCA\OpenRegister\Listener\TaskBlockerReleaseListener::class
		);
		$context->registerEventListener(
			\OCA\OpenRegister\Event\FlowRunTerminalEvent::class,
			\OCA\OpenRegister\Listener\CaseRunTerminalListener::class
		);
		$context->registerEventListener(ObjectUpdatedEvent::class, \OCA\OpenRegister\Listener\CaseObjectEventListener::class);
		$context->registerEventListener(ObjectTransitionedEvent::class, \OCA\OpenRegister\Listener\CaseObjectEventListener::class);
		// Routed through FlowTriggerListener, the ONE object-trigger path (the
		// branch that retired EventCatalogListener as a duplicate). The case
		// branch moved with the retirement, and gains what the one path has:
		// the subject's register/schema resolved to the SLUGS the trigger
		// index stores — CaseItemTransitionedEvent::getSubject() answers the
		// item's numeric ids — and the acting user attributed to the run.
		$context->registerEventListener(
			\OCA\OpenRegister\Event\CaseItemTransitionedEvent::class,
			\OCA\OpenRegister\Listener\FlowTriggerListener::class
		);
		// Plan items on the shared clock (one-engine-bpmn-and-cmmn): entering
		// active arms an item's timers, a terminal state cancels them, and an
		// enforcing expiry terminates the item. The case layer keeps no clock.
		$context->registerEventListener(
			\OCA\OpenRegister\Event\CaseItemTransitionedEvent::class,
			\OCA\OpenRegister\Listener\CaseItemTimerListener::class
		);
		$context->registerEventListener(
			\OCA\OpenRegister\Event\FlowTimerFiredEvent::class,
			\OCA\OpenRegister\Listener\CaseItemTimerListener::class
		);

		// Business-timer cancellation propagation (flow-business-timers, design
		// D-9): the SAME terminal event, and the run-terminal one, also cancel
		// the subject's open business timers. TaskTerminalEvent fires right
		// after the terminal write commits (see TaskService::transactional),
		// FlowRunTerminalEvent inside the run's own write; the listener is
		// idempotent and never deletes, and the invariant repair step counts
		// anything a crash window leaves behind.
		$context->registerEventListener(
			\OCA\OpenRegister\Event\TaskTerminalEvent::class,
			\OCA\OpenRegister\Listener\FlowTimerSubjectTerminalListener::class
		);
		$context->registerEventListener(
			\OCA\OpenRegister\Event\FlowRunTerminalEvent::class,
			\OCA\OpenRegister\Listener\FlowTimerSubjectTerminalListener::class
		);
	}//end registerFlowAndTaskListeners()

	/**
	 * The rules a save passes: lifecycle annotations, identifiers, field rules, validations, calculations, constraints and quotas.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 */
	private static function registerSaveRuleListeners(IRegistrationContext $context): void {
		// Lifecycle annotation listeners — see x-openregister-lifecycle.
		// Order matters: initial state runs on creating; validation runs on updating.
		$context->registerEventListener(ObjectCreatingEvent::class, LifecycleInitialStateListener::class);

		// The generated identifier (`generated-identifier`). Registered beside the
		// lifecycle initial state and for the same reason: the value has to be in
		// the object's body BEFORE it is written, or the object's first version is
		// the one without a number. The same listener freezes it on update, because
		// a frozen identifier that is not frozen fails in the quietest way there
		// is: the number in the letter stops matching the record, and nothing errors.
		$context->registerEventListener(ObjectCreatingEvent::class, GeneratedIdentifierListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, GeneratedIdentifierListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, LifecycleValidationListener::class);

		// Per-state field rules — see x-openregister-lifecycle.states.<state>.fields.
		// Registered AFTER LifecycleInitialStateListener, which stamps the
		// initial state onto a create: reading the state before that listener
		// has run would resolve a create against no state at all and let a
		// required field through. On an update it runs after
		// LifecycleValidationListener for the same reason the approval gate
		// does — a transition nobody declared is not worth asking field
		// questions about.
		$context->registerEventListener(ObjectCreatingEvent::class, StateFieldRuleListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, StateFieldRuleListener::class);

		// The administrator's own checks, on the SAME two events, which is the
		// whole of REQ-RCT-005: every write funnels through the two mapper
		// methods that dispatch these, so a validation cannot be skipped by a
		// path added later, and RuleEvaluationPointTest names that path if one
		// tries (row 11.53).
		$context->registerEventListener(ObjectCreatingEvent::class, AdministeredValidationListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, AdministeredValidationListener::class);

		// A working calendar was saved, so the deadlines it governs are
		// re-projected — off the write, as one queued job per calendar version
		// (row Q8.17, ADR-078). On the UPDATED event rather than the UPDATING
		// one: nothing should be recomputed against a calendar whose save might
		// still be refused.
		$context->registerEventListener(ObjectUpdatedEvent::class, WorkingCalendarChangedListener::class);

		// A materialised calculation that reads `@ref.<name>.<field>` is refreshed
		// on the objects that point at an updated object when that field changed.
		$context->registerEventListener(ObjectUpdatedEvent::class, \OCA\OpenRegister\Listener\ReferencedCalculationListener::class);

		// A changed date that other records' destruction date reads through a
		// relation queues their recalculation, off the save.
		$context->registerEventListener(ObjectUpdatedEvent::class, RelatedRetentionRecalculationListener::class);

		// Approval-chains declarative wiring — see x-openregister-approval-chains.
		// The annotation is validated at schema save; the gate compiles it into
		// a task template on demand and blocks any lifecycle transition it
		// names until the object's approval SEQUENCE completes with an
		// approving outcome (flow-approval-consolidation). Registered
		// immediately after LifecycleValidationListener: transition legality
		// must be established before approval gating runs against it.
		// Declared flows (`x-openregister-flows`) are MATERIALISED into the flow
		// store on schema save, because a flow lives in its own table rather
		// than being read off the schema at runtime like every other
		// x-openregister-* extension. Importing on save (not only on register
		// import) means a declared flow and a builder-authored one land in the
		// same place, and a re-import updates rather than duplicates.
		$context->registerEventListener(SchemaCreatedEvent::class, SchemaFlowImportListener::class);
		$context->registerEventListener(SchemaUpdatedEvent::class, SchemaFlowImportListener::class);

		$context->registerEventListener(SchemaCreatedEvent::class, ApprovalChainAnnotationInstaller::class);
		$context->registerEventListener(SchemaUpdatedEvent::class, ApprovalChainAnnotationInstaller::class);

		// A schema written by anything but the schema API (the configuration
		// importer, the import endpoint, the AI and MCP tools) re-checks the
		// published forms that submit into it, as the API does itself.
		$context->registerEventListener(SchemaUpdatedEvent::class, \OCA\OpenRegister\Listener\FormDestinationSchemaListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, ApprovalChainGateListener::class);

		// Sequence progression (flow-approval-consolidation): a committed
		// terminal task that belongs to a sequence enables the next position,
		// or closes the sequence, in the SAME request as the decision.
		$context->registerEventListener(
			\OCA\OpenRegister\Event\TaskTerminalEvent::class,
			\OCA\OpenRegister\Listener\TaskSequenceProgressListener::class
		);

		// Lifecycle action executor — see x-openregister-lifecycle.transitions[*].actions[].
		// Runs the declared actions of a matched transition on the save path, so
		// they fire for EVERY transition form — a named TransitionEngine action
		// AND a plain list-form edit of the lifecycle field (issue #427). Registered
		// after the validation + approval-gate listeners: a transition's actions
		// must only run once its legality is established and no gate has blocked it
		// (the listener checks isPropagationStopped()). A declared action naming an
		// unregistered handler fails loudly via LifecycleActionRegistry.
		$context->registerEventListener(ObjectUpdatingEvent::class, LifecycleActionListener::class);

		// Calculations annotation listener — materialises declared calculations
		// into the object payload before persistence (see x-openregister-calculations).
		$context->registerEventListener(ObjectCreatingEvent::class, CalculationOnSaveListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, CalculationOnSaveListener::class);

		// Consent envelope listener — fills evidentiary fields on every newly
		// appended consent-shaped array entry and refuses any write that
		// mutates or drops an already-persisted entry (see x-openregister-consent).
		$context->registerEventListener(ObjectCreatingEvent::class, ConsentEnvelopeOnSaveListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, ConsentEnvelopeOnSaveListener::class);

		// Quality annotation listener — materialises a per-object data-quality
		// score (0-1) into the object payload before persistence
		// (see x-openregister-quality). MDM foundation capability.
		$context->registerEventListener(ObjectCreatingEvent::class, QualityScoreOnSaveListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, QualityScoreOnSaveListener::class);

		// Survivorship annotation listener — materialises a declared golden
		// record + attribute provenance into the object payload before
		// persistence (see x-openregister-survivorship). MDM capability.
		$context->registerEventListener(ObjectCreatingEvent::class, SurvivorshipRecomputeListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, SurvivorshipRecomputeListener::class);

		// Working-calendar write guard — runs WorkingCalendar::fromArray() at
		// WRITE time, so the objects API, the admin page and a configuration
		// import refuse the same calendar with the same message. Before this,
		// the same validator only ran at ARM time: a malformed calendar was
		// accepted on save and surfaced days later on an unrelated timer.
		$context->registerEventListener(ObjectCreatingEvent::class, WorkingCalendarValidationListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, WorkingCalendarValidationListener::class);
		$context->registerEventListener(ObjectCreatingEvent::class, DecisionTableValidationListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, DecisionTableValidationListener::class);

		// ... and the other half: a calendar an armed or suspended timer names
		// cannot be deleted, because the resolver refuses an unknown name
		// rather than downgrading to weekdays.
		$context->registerEventListener(ObjectDeletingEvent::class, WorkingCalendarDeleteGuardListener::class);

		// Code-list lifecycle — a value outside its validity window, a broader
		// value under a leaf-only property and two values of one exclusive
		// group are all refused at WRITE time, on every door, so a retired
		// resultaattype cannot be chosen again while every dossier that
		// already holds it keeps reading correctly.
		$context->registerEventListener(ObjectCreatingEvent::class, CodedValueValidationListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, CodedValueValidationListener::class);

		// The dependent value table (rules-engine-operability, REQ-REO-005).
		// Subscribed to both write events, so the create and the update reach
		// it identically and a write path added later cannot skip the table.
		$context->registerEventListener(ObjectCreatingEvent::class, DependentValueListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, DependentValueListener::class);

		// ... and the delete half: a value the product defines, or one that
		// objects still hold, is refused with its count. Closing the validity
		// window is the operation that is always safe.
		$context->registerEventListener(ObjectDeletingEvent::class, ConceptDeleteGuardListener::class);

		// A frozen object refuses its delete the way it refuses a write, on
		// every path that dispatches the deleting event.
		$context->registerEventListener(ObjectDeletingEvent::class, FrozenObjectDeleteGuardListener::class);

		// Named uniqueness constraints — a `refuse` constraint stops the write
		// naming the combination and the conflicting object; a `report` one
		// lets it through and records the breach on the object's validation
		// envelope, where it can be read afterwards. Both are real: a gemeente
		// refuses a second bezwaar and only reports a repeated e-mail.
		$context->registerEventListener(ObjectCreatingEvent::class, UniqueConstraintListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, UniqueConstraintListener::class);

		// A schema may cap how many of its objects one organisation holds
		// (`x-openregister-quota.perOrganisation`); a create past it is
		// refused with the count and the cap. Updates never count.
		$context->registerEventListener(ObjectCreatingEvent::class, ObjectQuotaListener::class);

		// Reverse-FK source-change listener — when a source object (declared via
		// a master schema's x-openregister-survivorship sourceLink.reverseFk)
		// is created/updated/deleted, recompute the referenced master's golden
		// record so it stays current as its sources change. MDM capability.
		$context->registerEventListener(ObjectCreatedEvent::class, SourceRecordChangeListener::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, SourceRecordChangeListener::class);
		$context->registerEventListener(ObjectDeletedEvent::class, SourceRecordChangeListener::class);
		// Schema lifecycle events invalidate the listener's cross-request
		// reverse-FK index cache (reverse-FK declarations live on schemas).
		$context->registerEventListener(SchemaCreatedEvent::class, SourceRecordChangeListener::class);
		$context->registerEventListener(SchemaUpdatedEvent::class, SourceRecordChangeListener::class);
		$context->registerEventListener(SchemaDeletedEvent::class, SourceRecordChangeListener::class);

		// Grantable-rights index: drop the cached menu of rights on ANY schema
		// write. Deliberately has no TTL to fall back on — a right removed from
		// a schema must stop being offered at the moment of the write, and a
		// stale permission menu is a bug that looks exactly like a correct one.
		$context->registerEventListener(SchemaCreatedEvent::class, GrantableRightsInvalidationListener::class);
		$context->registerEventListener(SchemaUpdatedEvent::class, GrantableRightsInvalidationListener::class);
		$context->registerEventListener(SchemaDeletedEvent::class, GrantableRightsInvalidationListener::class);

		// CRUD metric listener — persists an operational metric row per object
		// create/update/delete into `openregister_metrics`, which the canonical
		// production-observability spec requires for counters that survive PHP
		// request boundaries. Fail-soft: never aborts the write it observes.
		$context->registerEventListener(ObjectCreatedEvent::class, ObjectMetricsListener::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, ObjectMetricsListener::class);
		$context->registerEventListener(ObjectDeletedEvent::class, ObjectMetricsListener::class);
	}//end registerSaveRuleListeners()

	/**
	 * What follows a write: reported content, notifications, cache eviction, projections, handoffs and pruning.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 */
	private static function registerSideEffectListeners(IRegistrationContext $context): void {
		// Reported content: a removal is noted on every report filed against the
		// content, and the removal record names the copies taken at filing time.
		// Fail-soft: never blocks the removal it observes.
		$context->registerEventListener(
			ObjectDeletedEvent::class,
			\OCA\OpenRegister\Listener\ContentReportRemovalListener::class
		);

		// Context Chat submission listener — submits/removes object content
		// to OCP\ContextChat on create/update/delete for schemas opted in via
		// x-openregister-contextchat. Fail-soft: never aborts the write it
		// observes; safe no-op when `context_chat` is not installed.
		$context->registerEventListener(ObjectCreatedEvent::class, ContextChatSubmissionListener::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, ContextChatSubmissionListener::class);
		$context->registerEventListener(ObjectDeletedEvent::class, ContextChatSubmissionListener::class);

		// Notifications annotation listener — fires INotificationManager
		// notifications declared on the schema's x-openregister-notifications.
		$context->registerEventListener(ObjectCreatedEvent::class, AnnotationNotificationListener::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, AnnotationNotificationListener::class);
		$context->registerEventListener(ObjectTransitionedEvent::class, AnnotationNotificationListener::class);

		// Object-lifecycle flow triggers have ONE listener: FlowTriggerListener,
		// registered above with the other flow triggers. EventCatalogListener
		// used to be registered here for the SAME seven events, each handler
		// calling FlowTriggerService::fire() — every object event reached the
		// trigger service twice. The double-fire was invisible only because both
		// listeners fired the object's numeric register/schema ids against an
		// index that holds slugs, so neither ever matched; the moment the
		// vocabulary was fixed, two registrations would have queued every
		// matched flow twice per event. FlowTriggerListener is the one kept
		// because it is the superset: it attributes the acting user to the run
		// and carries the transition's action/from/to as context, both of which
		// EventCatalogListener dropped.

		// System-entity notification bridge — routes create/update signals from
		// OpenRegister's own system entities through the same annotation-notification
		// pipeline used for stored register objects (spec: openregister-system-notifications).
		$context->registerEventListener(RegisterCreatedEvent::class, SystemEntityNotificationListener::class);
		$context->registerEventListener(RegisterUpdatedEvent::class, SystemEntityNotificationListener::class);
		$context->registerEventListener(SchemaCreatedEvent::class, SystemEntityNotificationListener::class);
		$context->registerEventListener(SchemaUpdatedEvent::class, SystemEntityNotificationListener::class);

		// Authorization cache eviction when the POLICY behind a memoised verdict
		// is rewritten. PermissionHandler::clearInheritFromPublicCache() has
		// always documented that "any path that mutates authorization and then
		// re-reads it within the same request must bust this cache"; until this
		// registration, nothing in production called it or its sibling
		// clearPermissionCache(), so the invariant was stated and unenforced —
		// in the repo every other app inherits its RBAC from.
		$context->registerEventListener(SchemaUpdatedEvent::class, AuthorizationCacheInvalidationListener::class);
		$context->registerEventListener(SchemaDeletedEvent::class, AuthorizationCacheInvalidationListener::class);
		$context->registerEventListener(RegisterUpdatedEvent::class, AuthorizationCacheInvalidationListener::class);
		$context->registerEventListener(RegisterDeletedEvent::class, AuthorizationCacheInvalidationListener::class);

		// Aggregation cache eviction on every object write.
		$context->registerEventListener(ObjectCreatedEvent::class, AggregationCacheInvalidationListener::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, AggregationCacheInvalidationListener::class);
		$context->registerEventListener(ObjectDeletedEvent::class, AggregationCacheInvalidationListener::class);

		// Facet freshness on every object write: bumps the counter for the written
		// object's (register, schema) scope so the next facet read cannot serve a
		// bucket list computed before the write (openregister#3560).
		$context->registerEventListener(ObjectCreatedEvent::class, FacetCacheInvalidationListener::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, FacetCacheInvalidationListener::class);
		$context->registerEventListener(ObjectDeletedEvent::class, FacetCacheInvalidationListener::class);
		$context->registerEventListener(ObjectTransitionedEvent::class, FacetCacheInvalidationListener::class);

		// A transition becomes an interval a history filter can join. The
		// listener never fails the move: the projection is derived and
		// rebuildable, the transition is not.
		$context->registerEventListener(ObjectTransitionedEvent::class, StateHistoryProjectionListener::class);

		// Translation sidecar projection — keeps oc_openregister_translations in sync with JSONB property data.
		$context->registerEventListener(ObjectCreatedEvent::class, TranslationProjectionListener::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, TranslationProjectionListener::class);
		$context->registerEventListener(ObjectDeletedEvent::class, TranslationProjectionListener::class);
		$context->registerEventListener(ObjectTransitionedEvent::class, TranslationProjectionListener::class);
		$context->registerEventListener(ObjectTransitionedEvent::class, AggregationCacheInvalidationListener::class);

		// Webhook auto-create installer for x-openregister-notifications with webhook.persistent: true.
		$context->registerEventListener(SchemaCreatedEvent::class, NotificationsAnnotationInstaller::class);
		$context->registerEventListener(SchemaUpdatedEvent::class, NotificationsAnnotationInstaller::class);

		// Semantic-object handoff engine (ADR-051):
		// - lifecycle-triggered handoffs run off ObjectTransitionedEvent (real actors only);
		// - queue-mode drain triggers: schema save + app enable (a provider may
		// have appeared); the fallback HandoffQueueDrainJob catches the rest.
		$context->registerEventListener(ObjectTransitionedEvent::class, HandoffLifecycleListener::class);

		// Nomination at closure: a record reaching a state its schema declares
		// final gets its archiefnominatie and archiefactiedatum derived and
		// written, with the rule that produced each.
		$context->registerEventListener(
			ObjectTransitionedEvent::class,
			\OCA\OpenRegister\Listener\ArchivalNominationListener::class
		);
		$context->registerEventListener(SchemaCreatedEvent::class, HandoffQueueDrainListener::class);
		$context->registerEventListener(SchemaUpdatedEvent::class, HandoffQueueDrainListener::class);

		// Scheduled-notification per-object dedup pruning (Phase 3.4):
		// - drop dedup rows on object purge so a re-created UUID re-arms cleanly;
		// - drop dedup rows for rule keys removed/renamed in the schema annotation
		// so orphan state does not pile up after edits.
		$context->registerEventListener(ObjectDeletedEvent::class, NotificationDedupePruneListener::class);
		$context->registerEventListener(SchemaCreatedEvent::class, NotificationDedupeAnnotationSyncListener::class);
		$context->registerEventListener(SchemaUpdatedEvent::class, NotificationDedupeAnnotationSyncListener::class);

		// Watcher pruning (`object-watchers`): a deleted object must not leave
		// its audience behind, or a re-created uuid inherits followers who
		// never chose to follow it.
		$context->registerEventListener(ObjectDeletedEvent::class, WatcherPruneListener::class);

		// Assignment follows (`merge-follow-and-favourites`): the user a save
		// puts in a schema's `x-openregister-role: assignee` property follows
		// the object with notifications on.
		$context->registerEventListener(ObjectCreatedEvent::class, AssigneeFollowListener::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, AssigneeFollowListener::class);

		// Read state (`object-read-state`). A substantive change puts the object
		// back to unread for every reader but its author; a deletion takes the
		// read states with it and archives the notices that pointed at it, which
		// would otherwise sit unread for ever pointing at nothing.
		$context->registerEventListener(ObjectUpdatedEvent::class, ReadStateInvalidationListener::class);
		$context->registerEventListener(ObjectDeletedEvent::class, ReadStatePruneListener::class);

		// Threshold trigger evaluator: re-runs aggregations on writes and dispatches when thresholds are crossed.
		$context->registerEventListener(ObjectCreatedEvent::class, AggregationThresholdListener::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, AggregationThresholdListener::class);
		$context->registerEventListener(ObjectDeletedEvent::class, AggregationThresholdListener::class);
		$context->registerEventListener(ObjectTransitionedEvent::class, AggregationThresholdListener::class);
	}//end registerSideEffectListeners()

	/**
	 * Delivery to the outside: webhooks, GraphQL, push, front-end scripts, comments and activity.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 */
	private static function registerDeliveryListeners(IRegistrationContext $context): void {
		// WebhookEventListener for webhook delivery.
		// OPS-2: register for EVERY event the listener's extractPayload() handles,
		// not just create — otherwise update/delete/lock/revert/register/schema
		// webhooks silently never deliver.
		$context->registerEventListener(ObjectCreatedEvent::class, WebhookEventListener::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, WebhookEventListener::class);
		$context->registerEventListener(ObjectDeletedEvent::class, WebhookEventListener::class);
		$context->registerEventListener(RegisterCreatedEvent::class, WebhookEventListener::class);
		$context->registerEventListener(RegisterUpdatedEvent::class, WebhookEventListener::class);
		$context->registerEventListener(RegisterDeletedEvent::class, WebhookEventListener::class);
		$context->registerEventListener(SchemaCreatedEvent::class, WebhookEventListener::class);
		$context->registerEventListener(SchemaUpdatedEvent::class, WebhookEventListener::class);
		$context->registerEventListener(SchemaDeletedEvent::class, WebhookEventListener::class);

		// GraphQL subscription event listeners.
		$context->registerEventListener(ObjectCreatedEvent::class, GraphQLSubscriptionListener::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, GraphQLSubscriptionListener::class);
		$context->registerEventListener(ObjectDeletedEvent::class, GraphQLSubscriptionListener::class);

		// Notify_push real-time push listeners (soft-fail when notify_push not installed).
		$context->registerEventListener(ObjectCreatedEvent::class, NotifyPushListener::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, NotifyPushListener::class);
		$context->registerEventListener(ObjectDeletedEvent::class, NotifyPushListener::class);

		// ObjectCleanupListener cleans up notes and tasks when an object is deleted.
		$context->registerEventListener(ObjectDeletedEvent::class, ObjectCleanupListener::class);

		// ActivityEventListener publishes Nextcloud Activity events for entity lifecycle.
		$activityListener = \OCA\OpenRegister\Listener\ActivityEventListener::class;
		$context->registerEventListener(ObjectCreatedEvent::class, $activityListener);
		$context->registerEventListener(ObjectUpdatedEvent::class, $activityListener);
		$context->registerEventListener(ObjectDeletedEvent::class, $activityListener);
		$context->registerEventListener(RegisterCreatedEvent::class, $activityListener);
		$context->registerEventListener(RegisterUpdatedEvent::class, $activityListener);
		$context->registerEventListener(RegisterDeletedEvent::class, $activityListener);
		$context->registerEventListener(SchemaCreatedEvent::class, $activityListener);
		$context->registerEventListener(SchemaUpdatedEvent::class, $activityListener);
		$context->registerEventListener(SchemaDeletedEvent::class, $activityListener);
	}//end registerDeliveryListeners()

	/**
	 * Register the listeners that must see a draft (decision 227).
	 *
	 * A draft's saves and its delete dispatch DraftCreatedEvent,
	 * DraftUpdatedEvent and DraftDeletedEvent instead of the object events
	 * (ObjectEventGate), so nothing that reacts to a new object sees a
	 * half-typed one. The listeners here are the exception, and each is here
	 * because it keeps the stored draft itself right: what its owner's lists
	 * and counts show, the sidecar of its own values, and what must go when
	 * it goes. None of them tells anybody anything.
	 *
	 * 🔴 THIS IS THE ONLY PLACE A LISTENER MAY BE REGISTERED ON A DRAFT EVENT,
	 * and the list is closed: ObjectEventRuleTest compares it with the list
	 * in the spec and fails on any other. A new listener on object creation
	 * belongs on ObjectCreatedEvent, where it never sees a draft.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-a-draft-must-have-events-of-its-own-on-a-separate-path
	 */
	private static function registerDraftListeners(IRegistrationContext $context): void {
		$saved = [\OCA\OpenRegister\Event\DraftCreatedEvent::class, \OCA\OpenRegister\Event\DraftUpdatedEvent::class];
		$removed = \OCA\OpenRegister\Event\DraftDeletedEvent::class;

		// The owner sees their own draft in lists, counts and facets, so those caches follow it.
		// The translation sidecar holds the draft's own values per language.
		foreach ([...$saved, $removed] as $event) {
			$context->registerEventListener($event, AggregationCacheInvalidationListener::class);
			$context->registerEventListener($event, FacetCacheInvalidationListener::class);
			$context->registerEventListener($event, TranslationProjectionListener::class);
		}

		// What hangs on a draft goes with it: notes, tasks and links, watchers, read state, notification keys.
		$context->registerEventListener($removed, ObjectCleanupListener::class);
		$context->registerEventListener($removed, WatcherPruneListener::class);
		$context->registerEventListener($removed, ReadStatePruneListener::class);
		$context->registerEventListener($removed, NotificationDedupePruneListener::class);
	}//end registerDraftListeners()
}//end class
