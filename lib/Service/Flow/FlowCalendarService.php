<?php

/**
 * Queues a run for every calendar-trigger moment that has come due.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * A moment fires once: it is claimed with a conditional write before its run
 * is queued, so two ticks never both queue it, and a refused queue leaves it
 * fired rather than retried every tick. The first tick that sees a trigger
 * enabled records when it was enabled and places the records that already
 * exist; a moment that lay before that is skipped and counted on the trigger.
 * Disabling the flow forgets the enabling, so enabling it again skips the
 * moments that passed meanwhile.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use DateTimeInterface;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMoment;
use OCA\OpenRegister\Db\FlowMomentMapper;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fires due calendar moments through the run queue.
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
 */
class FlowCalendarService {

	/**
	 * The run trigger name of a calendar-fired run.
	 *
	 * @var string
	 */
	public const TRIGGER = 'calendar';

	/**
	 * At most this many due moments are handled per tick.
	 *
	 * @var integer
	 */
	public const BATCH = 200;

	/**
	 * App id.
	 *
	 * @var string
	 */
	private const APP_ID = 'openregister';

	/**
	 * App config key prefix: when a trigger was enabled.
	 *
	 * @var string
	 */
	private const ENABLED_KEY = 'flow_cal_enabled_';

	/**
	 * App config key prefix: moments skipped because they lay before the enabling.
	 *
	 * @var string
	 */
	private const SKIPPED_KEY = 'flow_cal_skipped_';

	/**
	 * Constructor.
	 *
	 * @param FlowCalendarIndex       $index     The calendar triggers and the index writer.
	 * @param FlowCalendarRecords     $records   Reads the records that exist when a trigger is enabled.
	 * @param FlowMomentMapper        $moments   The index.
	 * @param FlowRunServiceInterface $runs      The run queue.
	 * @param IAppConfig              $appConfig Holds the enabling moment and the skip count.
	 * @param LoggerInterface         $logger    Logs a refused run.
	 */
	public function __construct(
		private readonly FlowCalendarIndex $index,
		private readonly FlowCalendarRecords $records,
		private readonly FlowMomentMapper $moments,
		private readonly FlowRunServiceInterface $runs,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Queue the runs of every moment that has come due.
	 *
	 * @param DateTimeInterface $now The clock.
	 *
	 * @return list<string> The runs queued.
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/specs/flow-engine/spec.md#scenario-a-reminder-a-month-before-the-contract-ends
	 */
	public function fireDue(DateTimeInterface $now): array {
		$enabled = $this->noteEnabling(now: $now);
		if ($enabled === []) {
			return [];
		}

		$clock = DateTime::createFromInterface($now);
		$queued = [];
		foreach ($this->moments->findDue(now: $clock, limit: self::BATCH) as $moment) {
			$trigger = ($enabled[self::key(flowUuid: $moment->getFlowUuid(), nodeId: $moment->getNodeId())] ?? null);
			if ($trigger === null) {
				continue;
			}

			$run = $this->fire(moment: $moment, trigger: $trigger, now: $clock);
			if ($run !== null) {
				$queued[] = $run;
			}
		}

		return $queued;
	}//end fireDue()

	/**
	 * How many moments were skipped because they lay before the trigger was enabled.
	 *
	 * @param string $flowUuid The flow.
	 * @param string $nodeId   The calendar trigger node.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/specs/flow-engine/spec.md#scenario-enabling-a-trigger-does-not-fire-for-dates-already-past
	 */
	public function skippedBeforeEnabling(string $flowUuid, string $nodeId): int {
		return (int)$this->appConfig->getValueString(self::APP_ID, self::SKIPPED_KEY . self::key(flowUuid: $flowUuid, nodeId: $nodeId), '0');
	}//end skippedBeforeEnabling()

	/**
	 * The enabled triggers by key, recording a newly enabled one and forgetting a disabled one.
	 *
	 * @param DateTimeInterface $now The clock.
	 *
	 * @return array<string, array{flow: Flow, node: array<string, mixed>, enabledAt: int}>
	 */
	private function noteEnabling(DateTimeInterface $now): array {
		$enabled = [];
		foreach ($this->index->triggers() as $trigger) {
			$key = self::key(flowUuid: (string)$trigger['flow']->getUuid(), nodeId: (string)$trigger['node']['id']);
			if ($trigger['flow']->canDispatch() === false) {
				$this->appConfig->deleteKey(self::APP_ID, self::ENABLED_KEY . $key);
				continue;
			}

			$enabledAt = (int)$this->appConfig->getValueString(self::APP_ID, self::ENABLED_KEY . $key, '0');
			if ($enabledAt === 0) {
				$enabledAt = self::enablingOf(flow: $trigger['flow'], now: $now);
				$this->appConfig->setValueString(self::APP_ID, self::ENABLED_KEY . $key, (string)$enabledAt);
				$this->appConfig->setValueString(self::APP_ID, self::SKIPPED_KEY . $key, '0');
				$this->placeExisting(trigger: $trigger);
			}

			$enabled[$key] = $trigger + ['enabledAt' => $enabledAt];
		}

		return $enabled;
	}//end noteEnabling()

	/**
	 * When a newly seen trigger was enabled: the save that enabled its flow, never later than now.
	 *
	 * @param Flow              $flow The flow.
	 * @param DateTimeInterface $now  The clock.
	 *
	 * @return int A Unix timestamp.
	 */
	private static function enablingOf(Flow $flow, DateTimeInterface $now): int {
		$saved = $flow->getUpdated();
		if ($saved === null || $saved->getTimestamp() > $now->getTimestamp()) {
			return $now->getTimestamp();
		}

		return $saved->getTimestamp();
	}//end enablingOf()

	/**
	 * Place the records that exist when a trigger is enabled.
	 *
	 * @param array{flow: Flow, node: array<string, mixed>} $trigger The trigger.
	 *
	 * @return void
	 */
	private function placeExisting(array $trigger): void {
		$config = (array)($trigger['node']['config'] ?? []);
		try {
			$records = $this->records->recordsOf(register: (string)($config['register'] ?? ''), schema: (string)($config['schema'] ?? ''));
			foreach ($records as $uuid => $data) {
				$this->index->place(flow: $trigger['flow'], node: $trigger['node'], objectUuid: $uuid, data: $data);
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowCalendarService] Could not place the existing records of a newly enabled calendar trigger: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $trigger['flow']->getUuid()]
			);
		}
	}//end placeExisting()

	/**
	 * Fire or skip one due moment.
	 *
	 * @param FlowMoment                                                      $moment  The moment.
	 * @param array{flow: Flow, node: array<string, mixed>, enabledAt: int} $trigger Its trigger.
	 * @param DateTime                                                        $now     The clock.
	 *
	 * @return string|null The run queued.
	 */
	private function fire(FlowMoment $moment, array $trigger, DateTime $now): ?string {
		$key = self::key(flowUuid: $moment->getFlowUuid(), nodeId: $moment->getNodeId());
		if ($moment->getDueAt()->getTimestamp() < $trigger['enabledAt']) {
			if ($this->moments->claim(moment: $moment, now: $now, skip: true) === true) {
				$skipped = ($this->skippedBeforeEnabling(flowUuid: $moment->getFlowUuid(), nodeId: $moment->getNodeId()) + 1);
				$this->appConfig->setValueString(self::APP_ID, self::SKIPPED_KEY . $key, (string)$skipped);
			}

			return null;
		}

		if ($this->moments->claim(moment: $moment, now: $now) === false) {
			return null;
		}

		$config = (array)($trigger['node']['config'] ?? []);
		try {
			$run = $this->runs->queueRun(
				flowId: $moment->getFlowUuid(),
				options: new FlowRunQueueOptions(
					subject: ['uuid' => $moment->getObjectUuid(), 'register' => (string)($config['register'] ?? ''), 'schema' => (string)($config['schema'] ?? '')],
					trigger: self::TRIGGER,
					context: [
						'payload' => [
							'flowId' => $moment->getFlowUuid(),
							'nodeId' => $moment->getNodeId(),
							'objectUuid' => $moment->getObjectUuid(),
							'dateValue' => $moment->getDateValue(),
							'dueAt' => $moment->getDueAt()->format(DATE_ATOM),
						],
					]
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowCalendarService] A due calendar moment was not run: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $moment->getFlowUuid(), 'object' => $moment->getObjectUuid()]
			);
			return null;
		}

		$runUuid = (string)$run->getUuid();
		$this->moments->recordRun(moment: $moment, runUuid: $runUuid);

		return $runUuid;
	}//end fire()

	/**
	 * The key of one trigger: a digest, so the app config key stays under its 64-character limit.
	 *
	 * @param string $flowUuid The flow.
	 * @param string $nodeId   The node.
	 *
	 * @return string
	 */
	private static function key(string $flowUuid, string $nodeId): string {
		return md5($flowUuid . '|' . $nodeId);
	}//end key()
}//end class
