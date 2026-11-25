<?php

/**
 * Keeps the due-moment index of calendar flow triggers in step with the records.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Every calendar trigger (`openregister.trigger-calendar`) holds one row per
 * record of its schema that carries a date: the date value and the moment it
 * comes due. A changed date moves the moment and makes it wait again, a
 * cleared date or a deleted record removes it. A record whose date did not
 * change keeps its row, fired or not, so one date value starts at most one run.
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
use DateTimeImmutable;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\FlowMoment;
use OCA\OpenRegister\Db\FlowMomentMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerCalendarMoment;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes, moves and removes the moments of calendar triggers.
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
 */
class FlowCalendarIndex {

	/**
	 * The calendar trigger node type.
	 *
	 * @var string
	 */
	public const NODE_TYPE = 'openregister.trigger-calendar';

	/**
	 * The calendar triggers read in this request.
	 *
	 * @var list<array{flow: Flow, node: array<string, mixed>}>|null
	 */
	private ?array $triggers = null;

	/**
	 * Counts a moment from a date value.
	 *
	 * @var TriggerCalendarMoment
	 */
	private readonly TriggerCalendarMoment $clock;

	/**
	 * Constructor.
	 *
	 * @param FlowMapperInterface       $flows     Finds the flows holding a calendar trigger.
	 * @param FlowMomentMapper          $moments   The index.
	 * @param FlowTriggerSlugsInterface $slugs     Turns an object's register and schema ids into slugs.
	 * @param WorkingCalendarService    $calendars Resolves a named working calendar.
	 * @param LoggerInterface           $logger    Logs a trigger that cannot be counted.
	 * @param TriggerCalendarMoment     $clock     Counts a moment from a date value.
	 */
	public function __construct(
		private readonly FlowMapperInterface $flows,
		private readonly FlowMomentMapper $moments,
		private readonly FlowTriggerSlugsInterface $slugs,
		private readonly WorkingCalendarService $calendars,
		private readonly LoggerInterface $logger,
		TriggerCalendarMoment $clock,
	) {
		$this->clock = $clock;
	}//end __construct()

	/**
	 * Every calendar trigger node, with its flow, enabled or not.
	 *
	 * @return list<array{flow: Flow, node: array<string, mixed>}>
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
	 */
	public function triggers(): array {
		if ($this->triggers !== null) {
			return $this->triggers;
		}

		$triggers = [];
		foreach ($this->flows->findWithNodeType(nodeType: self::NODE_TYPE) as $flow) {
			foreach (($flow->getNodes() ?? []) as $node) {
				if (is_array($node) === true && ($node['type'] ?? null) === self::NODE_TYPE && trim((string)($node['id'] ?? '')) !== '') {
					$triggers[] = ['flow' => $flow, 'node' => $node];
				}
			}
		}

		$this->triggers = $triggers;

		return $triggers;
	}//end triggers()

	/**
	 * Place a saved record's moment on every calendar trigger of its schema.
	 *
	 * @param ObjectEntity $object The created or updated record.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/specs/flow-engine/spec.md#scenario-a-moved-end-date-moves-the-reminder
	 */
	public function index(ObjectEntity $object): void {
		$uuid = (string)$object->getUuid();
		if ($uuid === '') {
			return;
		}

		$register = $this->identifiersOf(identifier: (string)$object->getRegister(), kind: 'register');
		$schema = $this->identifiersOf(identifier: (string)$object->getSchema(), kind: 'schema');
		foreach ($this->triggers() as $trigger) {
			$config = (array)($trigger['node']['config'] ?? []);
			if (in_array(trim((string)($config['register'] ?? '')), $register, true) === false
				|| in_array(trim((string)($config['schema'] ?? '')), $schema, true) === false
			) {
				continue;
			}

			$this->place(flow: $trigger['flow'], node: $trigger['node'], objectUuid: $uuid, data: $object->getObject());
		}
	}//end index()

	/**
	 * Remove a deleted record's moments.
	 *
	 * @param string $objectUuid The record.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/specs/flow-engine/spec.md#scenario-a-cleared-date-or-a-deleted-record-cancels-the-run
	 */
	public function remove(string $objectUuid): void {
		if ($objectUuid === '') {
			return;
		}

		$this->moments->deleteForObject(objectUuid: $objectUuid);
	}//end remove()

	/**
	 * Write, move or remove one trigger's moment for one record.
	 *
	 * @param Flow                 $flow       The flow.
	 * @param array<string, mixed> $node       The calendar trigger node.
	 * @param string               $objectUuid The record.
	 * @param array<string, mixed> $data       The record's data.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
	 */
	public function place(Flow $flow, array $node, string $objectUuid, array $data): void {
		$config = (array)($node['config'] ?? []);
		$value = ($data[(string)($config['dateProperty'] ?? '')] ?? null);
		$dateValue = '';
		if (is_string($value) === true) {
			$dateValue = trim($value);
		}

		$flowUuid = (string)$flow->getUuid();
		$nodeId = (string)$node['id'];
		$existing = $this->moments->findMoment(flowUuid: $flowUuid, nodeId: $nodeId, objectUuid: $objectUuid);
		$due = $this->dueAt(flow: $flow, config: $config, dateValue: $dateValue);
		if ($due === null) {
			if ($existing !== null) {
				$this->moments->delete($existing);
			}

			return;
		}

		if ($existing === null) {
			$moment = new FlowMoment();
			$moment->setFlowUuid($flowUuid);
			$moment->setNodeId($nodeId);
			$moment->setObjectUuid($objectUuid);
			$moment->setDateValue($dateValue);
			$moment->setDueAt($due);
			$this->moments->insert($moment);
			return;
		}

		$this->move(moment: $existing, dateValue: $dateValue, due: $due);
	}//end place()

	/**
	 * Move an indexed moment; a new date value makes it wait again.
	 *
	 * @param FlowMoment $moment    The indexed moment.
	 * @param string     $dateValue The record's date value now.
	 * @param DateTime   $due       The moment it comes due now.
	 *
	 * @return void
	 */
	private function move(FlowMoment $moment, string $dateValue, DateTime $due): void {
		$sameDate = ($moment->getDateValue() === $dateValue);
		if ($sameDate === true && $moment->getDueAt()->getTimestamp() === $due->getTimestamp()) {
			return;
		}

		$moment->setDueAt($due);
		if ($sameDate === false) {
			$moment->setDateValue($dateValue);
			$moment->setFiredAt(null);
			$moment->setRunUuid(null);
			$moment->setSkipped(false);
		}

		$this->moments->update($moment);
	}//end move()

	/**
	 * When a date value comes due on a trigger, or null when it holds no date.
	 *
	 * @param Flow                 $flow      The flow, whose organisation scopes the working calendar.
	 * @param array<string, mixed> $config    The trigger's config.
	 * @param string               $dateValue The date value.
	 *
	 * @return DateTime|null
	 */
	private function dueAt(Flow $flow, array $config, string $dateValue): ?DateTime {
		if ($dateValue === '') {
			return null;
		}

		try {
			$calendar = null;
			$slug = trim((string)($config['workingCalendar'] ?? ''));
			if ($slug !== '') {
				$calendar = $this->calendars->resolve(calendarSlug: $slug, organisation: $flow->getOrganisation());
			}

			$moment = $this->clock->moment(value: $dateValue, offset: trim((string)($config['offset'] ?? '')), calendar: $calendar);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowCalendarIndex] A calendar trigger\'s moment cannot be counted: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $flow->getUuid()]
			);
			return null;
		}

		if ($moment === null) {
			return null;
		}

		return DateTime::createFromImmutable(DateTimeImmutable::createFromInterface($moment));
	}//end dueAt()

	/**
	 * The id and the slug a trigger may name a register or schema by.
	 *
	 * @param string $identifier The object's register or schema id.
	 * @param string $kind       `register` or `schema`.
	 *
	 * @return list<string>
	 */
	private function identifiersOf(string $identifier, string $kind): array {
		$slug = match ($kind) {
			'register' => $this->slugs->registerSlug(identifier: $identifier),
			default => $this->slugs->schemaSlug(identifier: $identifier),
		};

		return array_values(array_unique(array_filter([$identifier, $slug], static fn (string $v): bool => $v !== '')));
	}//end identifiersOf()
}//end class
