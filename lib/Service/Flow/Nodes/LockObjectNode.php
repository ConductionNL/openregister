<?php

/**
 * Take a run-scoped lock on an object, or park the run until it frees.
 *
 * WHY A RUN LOCK AND NOT A USER LOCK
 * ----------------------------------
 * Object locks used to be keyed on the user alone. Two flow runs executing
 * under the same `runAs` therefore could not conflict: the second took the
 * extend branch, pushed the first's expiry out, and was handed the object. On
 * an instance where flows run as one service account that is EVERY pair of
 * runs. This node takes a lock keyed on the run, which refuses every other
 * caller including the run's own `runAs` user acting as a person.
 *
 * WHY SUSPEND RATHER THAN FAIL
 * ----------------------------
 * A contended lock is not an error, it is a queue. Failing the run would turn
 * ordinary contention into an incident and lose whatever the run had already
 * done; proceeding without the lock would be the data race the lock exists to
 * prevent. So the node parks the run and tries again on the next heartbeat,
 * which is the mechanism the engine already has for waiting: no second
 * waiting machinery, no poll loop inside a step.
 *
 * THE `resumeAt` MUST NOT BE NULL
 * -------------------------------
 * 🔴 A null `resumeAt` means "waiting on an external SIGNAL", and
 * `FlowRunMapper::findAbandonedSignals()` FAILS such a run after 14 days
 * without ever waking it. A lock waits on a clock, never on a signal, so
 * every suspension here carries a concrete time.
 *
 * THE BUDGET IS STAMPED ONCE
 * --------------------------
 * The deadline goes into the node's own resume slot on the FIRST attempt and
 * is never restamped. Recomputing it per attempt would reset the budget on
 * every heartbeat and the node would wait forever while appearing to have a
 * bound. Since openregister#3358 the slot survives a pass that legitimately
 * ends `queued`, so the deadline is not lost between attempts either.
 *
 * WHEN THE BUDGET RUNS OUT THE STEP FAILS. It does not break the lock:
 * letting any flow author defeat any other flow author's lock by waiting
 * would make the lock advisory again, in a new way. Breaking one is an
 * administrator's act, and it is audited.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-step-takes-a-lock-or-parks-the-run-and-retries
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use DateTime;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunSubjectRecorder;
use OCA\OpenRegister\Service\Flow\FlowSubjectCandidates;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\WorkflowEngine\IManager;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Take a run-scoped object lock, parking the run while it is contended.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class LockObjectNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm, IFlowNodeTaxonomy, LockObjectNodeInterface {

	/**
	 * The step type this node answers to.
	 *
	 * @var string
	 */
	public const TYPE = 'openregister.lock-object';

	/**
	 * Default lock duration in seconds.
	 *
	 * @var int
	 */
	public const DEFAULT_DURATION = 3600;

	/**
	 * Default wait budget in seconds. An hour: long enough to outlast a
	 * human-scale step upstream, short enough that a wedged run surfaces the
	 * same working day.
	 *
	 * @var int
	 */
	public const DEFAULT_WAIT_SECONDS = 3600;

	/**
	 * Retry floor in seconds.
	 *
	 * A wake finer than the cron period cannot happen, so asking for one only
	 * makes the run look busier than it is.
	 *
	 * @var int
	 */
	public const MIN_RETRY_SECONDS = 60;

	/**
	 * Retry ceiling in seconds.
	 *
	 * @var int
	 */
	public const MAX_RETRY_SECONDS = 900;

	/**
	 * Resume-slot key holding the absolute wait deadline, stamped ONCE.
	 *
	 * @var string
	 */
	public const SLOT_DEADLINE = 'lockWaitDeadline';

	/**
	 * Resume-slot key holding the attempt count, which drives the backoff.
	 *
	 * @var string
	 */
	public const SLOT_ATTEMPTS = 'lockAttempts';

	/**
	 * Refusal when the run did not identify itself.
	 *
	 * @var string
	 */
	private const REFUSE_NO_RUN = 'A lock step needs the run it belongs to; this run did not identify itself.';

	/**
	 * Refusal when the run names no acting identity: a lock must be attributable.
	 *
	 * @var string
	 */
	private const REFUSE_NO_IDENTITY = 'This flow run has no acting identity (runAs); a lock must be attributable.';

	/**
	 * Refusal when an item names no object.
	 *
	 * @var string
	 */
	private const REFUSE_NO_TARGET = 'A lock step could not work out which object to lock from the item it received.';

	/**
	 * The run, owner and target checks this step shares with the other lock step.
	 *
	 * @var LockStepContext
	 */
	private readonly LockStepContext $lockContext;

	/**
	 * Translations.
	 *
	 * @var IL10N
	 */
	private readonly IL10N $l10n;

	/**
	 * Resolves the palette icon.
	 *
	 * @var IURLGenerator
	 */
	private readonly IURLGenerator $urls;

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects Object lock operations, RBAC-enforcing.
	 * @param IUserManager $userManager Resolves the run's acting identity.
	 * @param FlowNodePresentation $presentation Translations and the palette icon.
	 * @param FlowRunSubjectRecorder|null $subjects Records the locked object under the declared role.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		IUserManager $userManager,
		FlowNodePresentation $presentation,
		private readonly ?FlowRunSubjectRecorder $subjects = null,
	) {
		$this->l10n = $presentation->l10n;
		$this->urls = $presentation->urls;
		$this->lockContext = new LockStepContext(userManager: $userManager, l10n: $this->l10n);
	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The node id.
	 */
	public function getId(): string {
		return self::TYPE;
	}//end getId()

	/**
	 * The palette label.
	 *
	 * @return string The display name.
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Lock an object');
	}//end getDisplayName()

	/**
	 * The palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-step-takes-a-lock-or-parks-the-run-and-retries
	 */
	public function getDescription(): string {
		return $this->l10n->t(
			'Hold an object for this run so nobody else can change it. If another run holds it, this step waits and tries again.'
		);
	}//end getDescription()

	/**
	 * The palette icon.
	 *
	 * @return string The icon path.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-node-the-engine-registers-is-offered-in-the-palette
	 */
	public function getIcon(): string {
		// The APP's icon, not core's. `core/img/actions/lock.svg` does not
		// exist in NC 33 or 34, `imagePath()` throws for an image the server
		// does not ship, and this node was therefore absent from the editor's
		// palette entirely. An icon the app ships cannot go missing under it.
		return $this->urls->imagePath('openregister', 'lock.svg');
	}//end getIcon()

	/**
	 * Available in both flow scopes.
	 *
	 * @param int $scope The workflow engine scope.
	 *
	 * @return bool True when available.
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * Top-level configuration keys.
	 *
	 * @return array<int, string> The keys.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-step-takes-a-lock-or-parks-the-run-and-retries
	 */
	public function configKeys(): array {
		return ['uuid', 'duration', 'waitSeconds', 'process', 'subjectRole'];
	}//end configKeys()

	/**
	 * The editor form.
	 *
	 * @return array<int, array<string, mixed>> The fields.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-step-takes-a-lock-or-parks-the-run-and-retries
	 */
	public function configForm(): array {
		return [
			[
				'key' => 'uuid',
				'label' => $this->l10n->t('Object'),
				'type' => 'text',
				'help' => $this->l10n->t('Which object to lock. Leave empty to lock the object the step receives.'),
			],
			[
				'key' => 'duration',
				'label' => $this->l10n->t('Hold for (seconds)'),
				'type' => 'number',
				'help' => $this->l10n->t('How long the lock survives if this run never releases it. Defaults to one hour.'),
			],
			[
				'key' => 'waitSeconds',
				'label' => $this->l10n->t('Wait at most (seconds)'),
				'type' => 'number',
				'help' => $this->l10n->t('How long to keep retrying while another run holds the object. Defaults to one hour.'),
			],
			[
				'key' => 'process',
				'label' => $this->l10n->t('Reason'),
				'type' => 'text',
				'help' => $this->l10n->t('What the lock is for. Shown to anyone whose write is refused.'),
			],
			[
				'key' => 'subjectRole',
				'label' => $this->l10n->t('Record the locked object as'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'Your own word for what this object is to this flow, such as "case" or "besluit". '
					.'A later step can then attach its task to it by that name. '
					.'Leave it empty and the step records nothing.'
				),
			],
		];
	}//end configForm()

	/**
	 * Refuse a configuration the run could not act on.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the configuration cannot be run.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-step-takes-a-lock-or-parks-the-run-and-retries
	 */
	public function validateConfig(array $config): void {
		$this->requirePositiveSeconds(config: $config, key: 'duration');
		$this->requirePositiveSeconds(config: $config, key: 'waitSeconds');

		$uuid = ($config['uuid'] ?? null);
		if ($uuid !== null && is_string($uuid) === false) {
			throw new UnexpectedValueException($this->l10n->t('A lock step\'s object must be text.'));
		}
	}//end validateConfig()

	/**
	 * Refuse a duration that is not a whole number of seconds.
	 *
	 * @param array $config The step configuration.
	 * @param string $key The key to check.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the value cannot be a duration.
	 */
	private function requirePositiveSeconds(array $config, string $key): void {
		$value = ($config[$key] ?? null);
		if ($value === null || $value === '') {
			return;
		}

		if (is_numeric($value) === false || (int)$value < 1) {
			throw new UnexpectedValueException(
				$this->l10n->t('A lock step\'s "%s" must be a whole number of seconds, at least 1.', [$key])
			);
		}
	}//end requirePositiveSeconds()

	/**
	 * Take the lock, or park the run and try again later.
	 *
	 * @param array $items The incoming items.
	 * @param array $config The step configuration.
	 * @param array $context The run context.
	 *
	 * @return array The items, unchanged.
	 *
	 * @throws FlowSuspension When the lock is contended and the budget remains.
	 * @throws RuntimeException When the budget is spent, or the step cannot act.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-step-takes-a-lock-or-parks-the-run-and-retries
	 */
	public function execute(array $items, array $config, array $context): array {
		// An empty firing must not suspend the whole run. After a route, the
		// un-taken branch is marked and its steps still fire with zero items.
		if ($items !== []) {
			$this->validateConfig(config: $config);
			$this->lockTargets(run: new LockStepRun(items: $items, config: $config, context: $context, resume: $this->resumeSlot(context: $context)));
		}

		// Returning normally clears the slot, so a later re-entry of this node
		// starts a fresh budget, which is correct: it is a different wait.
		return $items;
	}//end execute()

	/**
	 * This node's resume slot; a lock step cannot wait without one.
	 *
	 * @param array<string, mixed> $context The run context.
	 *
	 * @return FlowNodeResumeState
	 *
	 * @throws RuntimeException When the run provided no slot.
	 */
	private function resumeSlot(array $context): FlowNodeResumeState {
		$resume = ($context[FlowNodeResumeState::CONTEXT_KEY] ?? null);
		if ($resume instanceof FlowNodeResumeState === false) {
			// Without a slot the node cannot remember its deadline, so every
			// heartbeat would restart the budget and the wait would never end.
			throw new RuntimeException(
				$this->l10n->t('A lock step needs a resume slot; this run did not provide one.')
			);
		}

		return $resume;
	}//end resumeSlot()

	/**
	 * Take the lock on every target, or park the run on the first contended one.
	 *
	 * @param LockStepRun $run The firing: items, configuration, run context and resume slot.
	 *
	 * @return void
	 *
	 * @throws FlowSuspension When a lock is contended and the budget remains.
	 * @throws RuntimeException When the budget is spent, or the step cannot act.
	 */
	private function lockTargets(LockStepRun $run): void {
		$hold = $this->holdFor(run: $run);
		$targets = $this->lockContext->targets(items: $run->items, config: $run->config, refusal: $this->l10n->t(self::REFUSE_NO_TARGET));
		$deadline = $this->deadline(run: $run);
		foreach ($targets as $uuid) {
			$this->lockOne(hold: $hold, attempt: new LockAttempt(resume: $run->resume, deadline: $deadline, uuid: $uuid));
		}

		// Every target is held. Only now, with the lock actually taken, is the
		// object something the run can honestly say it is working on.
		$this->recordSubject(run: $run, targets: $targets);
	}//end lockTargets()

	/**
	 * Who takes the lock, for which run and process, and for how long.
	 *
	 * @param LockStepRun $run The firing.
	 *
	 * @return LockHold
	 *
	 * @throws RuntimeException When the run did not identify itself or has no acting identity.
	 */
	private function holdFor(LockStepRun $run): LockHold {
		$runUuid = $this->lockContext->runUuid(context: $run->context, refusal: $this->l10n->t(self::REFUSE_NO_RUN));
		$owner = $this->lockContext->owner(context: $run->context, refusal: $this->l10n->t(self::REFUSE_NO_IDENTITY));
		$process = trim((string)($run->config['process'] ?? ''));
		if ($process === '') {
			$process = sprintf('flow run %s', $runUuid);
		}

		$duration = self::seconds(raw: ($run->config['duration'] ?? null), default: self::DEFAULT_DURATION);

		return new LockHold(owner: $owner, runUuid: $runUuid, process: $process, duration: $duration);
	}//end holdFor()

	/**
	 * Take one lock as the run's acting identity, or park or fail on contention.
	 *
	 * @param LockHold    $hold    Who takes it, for which run and process, for how long.
	 * @param LockAttempt $attempt The target, the resume slot and the wait deadline.
	 *
	 * @return void
	 *
	 * @throws FlowSuspension When the lock is contended and the budget remains.
	 * @throws RuntimeException When the budget is spent.
	 */
	private function lockOne(LockHold $hold, LockAttempt $attempt): void {
		try {
			$this->objects->runAs(
				$hold->owner,
				fn (): array => $this->objects->lockObject(
					identifier: $attempt->uuid,
					process: $hold->process,
					duration: $hold->duration,
					advisory: false,
					runUuid: $hold->runUuid,
					nodeId: $attempt->resume->nodeId()
				)
			);
		} catch (Throwable $e) {
			$this->parkOrGiveUp(attempt: $attempt, cause: $e);
		}
	}//end lockOne()

	/**
	 * Record the locked object under the role its author named.
	 *
	 * 🔑 AFTER THE LOCK, NEVER BEFORE. A step that parks and retries reaches
	 * this line only once every target is held, so the run never claims a
	 * subject it failed to lock.
	 *
	 * 🔴 ONE OBJECT, OR NONE — the same rule as the write node. A role is a
	 * singular name; a step that locked six objects has no single one to mean
	 * by it, so it records nothing and says so once. The register and schema
	 * are not recorded because this node never resolves them: it locks by uuid.
	 * A task attaching to this role is anchored by uuid alone, which is what
	 * the inbox and the sidebar match on.
	 *
	 * @param LockStepRun        $run     The firing: its configuration and run context.
	 * @param array<int, string> $targets The uuids this step locked.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-run-subjects/spec.md
	 */
	private function recordSubject(LockStepRun $run, array $targets): void {
		// The empty role is NOT guarded here. Recording is opt-in and the
		// recorder enforces that, so a second guard would be a second copy of
		// one rule — and this class is at its complexity budget, which is the
		// concrete cost of keeping the copy.
		$this->subjects?->recordOne(
			context: $run->context,
			candidates: new FlowSubjectCandidates(
				role: (string)($run->config['subjectRole'] ?? ''),
				uuids: array_values(array_unique($targets))
			)
		);

	}//end recordSubject()

	/**
	 * Park the run and retry, or fail because the budget is spent.
	 *
	 * @param LockAttempt $attempt The contended object, this node's resume slot and the absolute wait deadline.
	 * @param Throwable   $cause   Why the acquire failed.
	 *
	 * @return void
	 *
	 * @throws FlowSuspension To park the run.
	 * @throws RuntimeException When the budget is spent.
	 */
	private function parkOrGiveUp(LockAttempt $attempt, Throwable $cause): void {
		$now = new DateTime();
		if ($now >= $attempt->deadline) {
			// Fail, naming the holder. Do NOT break the lock and do NOT
			// proceed: proceeding is the data race the lock exists to stop.
			throw new RuntimeException(
				$this->l10n->t(
					'Waited as long as allowed for object %s, and it is still locked: %s',
					[$attempt->uuid, $cause->getMessage()]
				),
				0,
				$cause
			);
		}

		$attempts = ((int)$attempt->resume->get(self::SLOT_ATTEMPTS, 0) + 1);
		$attempt->resume->set(self::SLOT_ATTEMPTS, $attempts);

		throw new FlowSuspension(
			resumeAt: $this->nextAttemptAt(attempts: $attempts, deadline: $attempt->deadline),
			reason: sprintf('waiting for a lock on %s', $attempt->uuid)
		);
	}//end parkOrGiveUp()

	/**
	 * When to wake for the next attempt.
	 *
	 * Backoff is the node's own job: `resume_at` is an absolute wake time and
	 * the engine keeps no attempt counter. Doubling from the floor spares a
	 * long wait a wake every minute, and the result is clamped so it never
	 * overshoots the deadline: waking after the budget has expired would
	 * report the failure a whole interval late.
	 *
	 * @param int $attempts How many attempts have been made.
	 * @param DateTime $deadline The absolute wait deadline.
	 *
	 * @return DateTime The next wake time.
	 */
	private function nextAttemptAt(int $attempts, DateTime $deadline): DateTime {
		$backoff = (self::MIN_RETRY_SECONDS * (2 ** min($attempts - 1, 8)));
		$backoff = min($backoff, self::MAX_RETRY_SECONDS);

		$attemptAt = (new DateTime())->modify('+' . $backoff . ' seconds');
		if ($attemptAt > $deadline) {
			return $deadline;
		}

		return $attemptAt;
	}//end nextAttemptAt()

	/**
	 * The absolute wait deadline, stamped ONCE on the first attempt.
	 *
	 * @param LockStepRun $run The firing: this node's resume slot and the step configuration.
	 *
	 * @return DateTime The deadline.
	 */
	private function deadline(LockStepRun $run): DateTime {
		$stored = $run->resume->get(self::SLOT_DEADLINE, '');
		if (is_string($stored) === true && trim($stored) !== '') {
			try {
				return new DateTime($stored);
			} catch (Throwable $e) {
				// An unreadable deadline is restamped below rather than
				// treated as expired: a corrupt slot must not fail a run that
				// has done nothing wrong.
				$stored = '';
			}
		}

		$wait = self::seconds(raw: ($run->config['waitSeconds'] ?? null), default: self::DEFAULT_WAIT_SECONDS);
		$stamp = (new DateTime())->modify('+' . $wait . ' seconds')->format('c');
		$run->resume->set(self::SLOT_DEADLINE, $stamp);

		// Return the value as PERSISTED, not as computed. `format('c')` drops
		// sub-second precision, so the deadline this attempt compares against
		// would otherwise be microseconds later than the one every subsequent
		// attempt reads back, and the last retry could be scheduled just past
		// the bound it is supposed to respect.
		return new DateTime($stamp);
	}//end deadline()

	/**
	 * Read a positive integer from the config.
	 *
	 * @param mixed $raw     The configured value.
	 * @param int   $default The fallback.
	 *
	 * @return int The value.
	 */
	private static function seconds(mixed $raw, int $default): int {
		if (is_numeric($raw) === false || (int)$raw < 1) {
			return $default;
		}

		return (int)$raw;
	}//end seconds()

	/**
	 * What kind of step this is. Calls the register to lock.
	 *
	 * @return string The BPMN kind.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-semantic-kind-drawn-from-bpmn
	 */
	public function getKind(): string {
		return IFlowNodeTaxonomy::KIND_SERVICE_TASK;

	}//end getKind()

	/**
	 * Where an author should look for this step.
	 *
	 * @return string The palette category.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-palette-category-independent-of-its-kind
	 */
	public function getCategory(): string {
		return IFlowNodeTaxonomy::CATEGORY_OBJECTS;

	}//end getCategory()
}//end class
