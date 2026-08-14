<?php

/**
 * Pauses the run until somebody — or something — answers.
 *
 * {@see WaitNode} pauses until a TIME passes, which the engine can predict. This
 * pauses until an EVENT happens, which it cannot: an approval granted, a
 * webhook received, a payment cleared. The difference matters more than it
 * sounds, because "when" is the one thing a scheduler needs and this node
 * cannot supply.
 *
 * HOW IT WAKES, AND WHY IT USES BOTH ROUTES. The signal arrives at
 * `POST /api/flow-runs/{uuid}/resume`, which parks the run as due so the next
 * worker pass advances it. That alone would be enough on a good day. It is not
 * enough on a bad one: a signal can be delivered while the run is still mid-walk
 * and has not suspended yet, and a delivery can simply fail. Either loses the
 * only wake-up the run was ever going to get, and a run suspended on a pure
 * signal is unreachable — nothing queries it, and `hasActiveRun()` counts it, so
 * it holds its flow's whole schedule shut behind it.
 *
 * So this node ALSO carries a heartbeat: it suspends with a `resumeAt` a few
 * minutes out, and on waking re-asks whether its answer has arrived. A delivered
 * signal wakes it in one worker pass; a lost one costs a heartbeat instead of
 * costing the flow. The safety net is the reason the node is safe to put in a
 * flow that matters, and it is cheap — a suspended run that wakes, finds nothing
 * and suspends again does no work.
 *
 * WHAT COUNTS AS AN ANSWER. The payload posted to the resume endpoint lands at
 * `context.signal`. This node reads `decision` from it and writes the whole
 * payload onto every item under `signalKey`, so the steps after it can branch on
 * what was decided and see who decided it. A resume with no `decision` is a
 * nudge, not an answer: the node suspends again. That is what makes an
 * accidental or duplicate POST harmless.
 *
 * REJECTION IS NOT FAILURE. A rejected approval is the flow working correctly —
 * somebody was asked and said no. So the default is to carry on and let the
 * author route on `decision`, and `failOnReject` is opt-in for the flows where
 * a no really is a fault.
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
 * @spec openspec/changes/or-flow-resume-state/specs/flow-resume-state/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use DateTime;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowStop;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use UnexpectedValueException;

/**
 * Suspends the run until an external signal decides how it continues.
 */
class AwaitSignalNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm {

	/**
	 * Minutes between heartbeats when the flow does not choose.
	 *
	 * Short enough that a lost signal is an inconvenience rather than an
	 * outage, long enough that a fortnight-long approval costs a few thousand
	 * no-op wake-ups rather than a few hundred thousand. The stock system cron
	 * runs every five minutes, so anything below that buys nothing at all.
	 *
	 * @var int
	 */
	private const DEFAULT_HEARTBEAT_MINUTES = 15;

	/**
	 * The floor a configured heartbeat is clamped to.
	 *
	 * Below the cron period the extra wake-ups cannot happen, so a flow asking
	 * for one every thirty seconds would get the same behaviour as five minutes
	 * while looking like it got what it asked for.
	 *
	 * @var int
	 */
	private const MIN_HEARTBEAT_MINUTES = 5;

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urls For the palette icon.
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
	) {

	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The id.
	 */
	public function getId(): string {
		return 'openregister.await-signal';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Wait for an answer');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 */
	public function getDescription(): string {
		return $this->l10n->t('Pause until someone approves, rejects, or another system reports back.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/confirm.svg');
	}//end getIcon()

	/**
	 * Waiting grants no privilege.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return boolean Whether it is available.
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * The config vocabulary of an await step.
	 *
	 * @return array<int, string> The accepted config keys.
	 */
	public function configKeys(): array {
		return ['question', 'assignee', 'signalKey', 'heartbeatMinutes', 'failOnReject'];
	}//end configKeys()

	/**
	 * The fields this node is edited through.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	public function configForm(): array {
		return [
			[
				'key' => 'question',
				'label' => $this->l10n->t('What is being asked'),
				'type' => 'text',
				'help' => $this->l10n->t('Shown to whoever has to answer, and written to the run log so a paused flow explains itself.'),
				'required' => true,
			],
			[
				'key' => 'assignee',
				'label' => $this->l10n->t('Who should answer'),
				'type' => 'text',
				'help' => $this->l10n->t('A user or group id. Recorded with the request; it does not by itself restrict who may answer.'),
			],
			[
				'key' => 'signalKey',
				'label' => $this->l10n->t('Field to store the answer in'),
				'type' => 'text',
				'help' => $this->l10n->t('The answer is written onto every item under this field, so later steps can route on it. Defaults to "signal".'),
			],
			[
				'key' => 'heartbeatMinutes',
				'label' => $this->l10n->t('Re-check every (minutes)'),
				'type' => 'number',
				'help' => $this->l10n->t('Safety net for a signal that never arrives. Lower is not faster — an answer wakes the run immediately either way.'),
			],
			[
				'key' => 'failOnReject',
				'label' => $this->l10n->t('Treat a rejection as a failure'),
				'type' => 'boolean',
				'help' => $this->l10n->t('Off by default: being told "no" is usually the flow working, not breaking. Route on the answer instead.'),
			],
		];
	}//end configForm()

	/**
	 * Reject an await that asks nothing.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When no question is set.
	 */
	public function validateConfig(array $config): void {
		if (trim((string)($config['question'] ?? '')) === '') {
			throw new UnexpectedValueException(
				$this->l10n->t('Say what is being asked, or nobody can answer it.')
			);
		}

	}//end validateConfig()

	/**
	 * Suspend until answered; pass the answer on when it arrives.
	 *
	 * @param array $items The input items.
	 * @param array $config The step configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The items, each carrying the answer.
	 *
	 * @throws FlowSuspension While no decision has arrived.
	 * @throws FlowStop When rejected and the step asked to fail on rejection.
	 */
	public function execute(array $items, array $config, array $context): array {
		$signal = $this->decisionFrom(context: $context);

		if ($signal === null) {
			$this->recordRequest(config: $config, context: $context);

			throw new FlowSuspension(
				resumeAt: $this->heartbeatAt(config: $config),
				reason: sprintf(
					'waiting for an answer: %s',
					trim((string)($config['question'] ?? 'approval'))
				)
			);
		}

		$decision = strtolower(trim((string)($signal['decision'] ?? '')));

		if ($decision === 'reject' || $decision === 'rejected') {
			if (($config['failOnReject'] ?? false) === true) {
				throw new FlowStop(
					reason: sprintf(
						'Rejected: %s',
						trim((string)($signal['reason'] ?? $config['question'] ?? 'no reason given'))
					),
					isError: true
				);
			}
		}

		$key = trim((string)($config['signalKey'] ?? ''));
		if ($key === '') {
			$key = 'signal';
		}

		// Onto every item rather than into the token, because the steps that
		// follow route per item; a Switch cannot branch on something only the
		// run holds.
		foreach ($items as $index => $item) {
			if (is_array($item) === false) {
				continue;
			}

			$item[$key] = $signal;
			$items[$index] = $item;
		}

		return $items;
	}//end execute()

	/**
	 * The decision this node is waiting for, if it has arrived.
	 *
	 * Null covers three cases that must all mean "keep waiting": no signal at
	 * all, a signal that is not a value bag, and a signal carrying no
	 * `decision`. The last is the one worth being deliberate about — a resume
	 * posted with an empty body is a nudge, and treating it as an answer would
	 * let a stray click approve something.
	 *
	 * @param array $context Run-level metadata.
	 *
	 * @return array<string, mixed>|null The decision payload, or null while unanswered.
	 */
	private function decisionFrom(array $context): ?array {
		$signal = ($context[FlowRunService::SIGNAL_CONTEXT_KEY] ?? null);
		if (is_array($signal) === false) {
			return null;
		}

		if (trim((string)($signal['decision'] ?? '')) === '') {
			return null;
		}

		return $signal;
	}//end decisionFrom()

	/**
	 * Note what is being waited for, so a paused run explains itself.
	 *
	 * Written to this node's resume slot, which means it survives the
	 * suspension and is scoped to this node — two await steps in one flow keep
	 * their own questions rather than overwriting each other's.
	 *
	 * Only on the FIRST pass. A heartbeat that wakes, finds no answer and
	 * suspends again must not restamp `askedAt`, or the record of how long
	 * somebody has been waiting resets every quarter of an hour — and "waiting
	 * 15 minutes" is exactly the reading that would stop anyone chasing it.
	 *
	 * @param array $config The step configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return void
	 */
	private function recordRequest(array $config, array $context): void {
		$resume = ($context[FlowNodeResumeState::CONTEXT_KEY] ?? null);
		if ($resume instanceof FlowNodeResumeState === false) {
			return;
		}

		if ($resume->has(key: 'askedAt') === true) {
			return;
		}

		$resume->merge(
			values: [
				'askedAt' => (new DateTime())->format('c'),
				'question' => trim((string)($config['question'] ?? '')),
				'assignee' => trim((string)($config['assignee'] ?? '')),
			]
		);

	}//end recordRequest()

	/**
	 * When to wake up and re-ask, absent an answer.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return DateTime The next heartbeat.
	 */
	private function heartbeatAt(array $config): DateTime {
		$minutes = (int)($config['heartbeatMinutes'] ?? self::DEFAULT_HEARTBEAT_MINUTES);
		if ($minutes < self::MIN_HEARTBEAT_MINUTES) {
			$minutes = self::MIN_HEARTBEAT_MINUTES;
		}

		return (new DateTime())->modify('+' . $minutes . ' minutes');
	}//end heartbeatAt()
}//end class
