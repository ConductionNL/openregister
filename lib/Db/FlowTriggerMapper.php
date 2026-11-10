<?php

/**
 * Reads and rebuilds the indexed trigger set.
 *
 * The rows here are DERIVED — `FlowTriggerDerivation` reads a flow's trigger
 * nodes and this writes the result. Nothing else may write them: a row that is
 * not reproducible from the flow's nodes is a subscription with no visible
 * cause, which is the failure mode the whole node model exists to remove.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-trigger-matching-must-not-scale-with-the-number-of-flows
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Db\Flow\FlowTriggerSet;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The indexed trigger set.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-trigger-matching-must-not-scale-with-the-number-of-flows
 */
class FlowTriggerMapper implements FlowTriggerMapperInterface {

	/**
	 * The table holding the derived trigger set.
	 *
	 * @var string
	 */
	public const TABLE = 'openregister_flow_triggers';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(
		private readonly IDBConnection $db,
	) {

	}//end __construct()

	/**
	 * The UUIDs of enabled flows subscribed to one object event.
	 *
	 * ONE indexed read, whatever the number of flows or the number of triggers
	 * each carries. `register` and `schema` are matched exactly — a trigger node
	 * cannot express "any", so there is no unscoped row to also match.
	 *
	 * @param string $event The event id.
	 * @param string $register The register slug the event fired on.
	 * @param string $schema The schema slug the event fired on.
	 *
	 * @return array<int, string> The flow UUIDs, each at most once.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-trigger-matching-must-not-scale-with-the-number-of-flows
	 */
	public function flowUuidsFor(string $event, string $register, string $schema): array {
		return $this->flowUuidsSubscribedTo(event: $event, table: new RegisterSchemaRef(register: $register, schema: $schema));
	}//end flowUuidsFor()

	/**
	 * The UUIDs of enabled flows subscribed to one event on one register and schema.
	 *
	 * ONE indexed read, whatever the number of flows or the number of triggers
	 * each carries. The register and schema slugs are matched exactly.
	 *
	 * @param string            $event The event id.
	 * @param RegisterSchemaRef $table The register and schema slugs the event fired on.
	 *
	 * @return array<int, string> The flow UUIDs, each at most once.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-trigger-matching-must-not-scale-with-the-number-of-flows
	 */
	public function flowUuidsSubscribedTo(string $event, RegisterSchemaRef $table): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('flow_uuid')
			->from(self::TABLE)
			->where($qb->expr()->eq('event', $qb->createNamedParameter($event)))
			->andWhere($qb->expr()->eq('register', $qb->createNamedParameter((string)$table->register)))
			->andWhere($qb->expr()->eq('schema_slug', $qb->createNamedParameter((string)$table->schema)))
			->andWhere($qb->expr()->eq('enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

		return $this->flowUuidsOf(qb: $qb);
	}//end flowUuidsSubscribedTo()

	/**
	 * Run a select of the flow_uuid column and return the uuids as strings.
	 *
	 * @param IQueryBuilder $qb The select.
	 *
	 * @return array<int, string> The flow UUIDs.
	 */
	private function flowUuidsOf(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		$uuids = [];
		while (($row = $result->fetch()) !== false) {
			$uuids[] = (string)$row['flow_uuid'];
		}

		$result->closeCursor();

		return $uuids;
	}//end flowUuidsOf()

	/**
	 * Every trigger row that names one schema, whatever the register or event.
	 *
	 * The rule inventory asks a different question from the hot path: not "does
	 * this event on this register and schema match a flow" but "which flows can
	 * act on objects of this schema at all". A disabled row is returned too,
	 * because a switched-off rule is exactly what the inventory has to show.
	 *
	 * @param string $schemaSlug The schema's slug, as the trigger node names it.
	 *
	 * @return array<int, array{flow_uuid: string, event: string, register: string, enabled: bool}> The rows.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function findBySchema(string $schemaSlug): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('flow_uuid', 'event', 'register', 'enabled')
			->from(self::TABLE)
			->where($qb->expr()->eq('schema_slug', $qb->createNamedParameter($schemaSlug)))
			->orderBy('flow_uuid', 'ASC')
			->addOrderBy('event', 'ASC');

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = $this->typedTriggerRow(row: $row);
		}

		$result->closeCursor();

		return $rows;
	}//end findBySchema()

	/**
	 * A trigger row with its values typed.
	 *
	 * @param array<string, mixed> $row The stored row.
	 *
	 * @return array{flow_uuid: string, event: string, register: string, enabled: bool}
	 */
	private function typedTriggerRow(array $row): array {
		return [
			'flow_uuid' => (string)$row['flow_uuid'],
			'event' => (string)$row['event'],
			'register' => (string)$row['register'],
			'enabled' => (bool)$row['enabled'],
		];
	}//end typedTriggerRow()

	/**
	 * The UUIDs of every flow represented in the index.
	 *
	 * Used to decide, in one read, which flows the column fallback still owns.
	 *
	 * @return array<int, string> The flow UUIDs.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-the-cutover-from-trigger-columns-to-trigger-nodes-must-be-proven-per-flow
	 */
	public function representedFlowUuids(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('flow_uuid')->from(self::TABLE);

		return $this->flowUuidsOf(qb: $qb);
	}//end representedFlowUuids()

	/**
	 * Replace one flow's trigger rows with the given set.
	 *
	 * Delete-then-insert rather than a diff: the set is small, and a diff would
	 * have to decide what "the same trigger" means when a node's config is
	 * edited — getting that wrong leaves a flow subscribed to an event it no
	 * longer declares, which is the one outcome worse than not being subscribed.
	 *
	 * @param FlowTriggerSet $set The flow's UUID, its derived triggers (each event/register/schema) and whether it is enabled.
	 *
	 * @return int The number of rows written.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-trigger-matching-must-not-scale-with-the-number-of-flows
	 */
	public function replaceFor(FlowTriggerSet $set): int {
		$this->deleteFor(flowUuid: $set->flowUuid);

		foreach ($set->triggers as $trigger) {
			$this->insertRow(row: self::triggerRow(flowUuid: $set->flowUuid, trigger: $trigger), enabled: $set->enabled);
		}

		return count($set->triggers);
	}//end replaceFor()

	/**
	 * The columns of one flow's trigger, before the enabled flag.
	 *
	 * @param string               $flowUuid The flow's UUID.
	 * @param array<string, mixed> $trigger  The derived trigger: event, register and schema.
	 *
	 * @return array<string, string> The flow uuid, event, register and schema slug, by column.
	 */
	private static function triggerRow(string $flowUuid, array $trigger): array {
		return [
			'flow_uuid' => $flowUuid,
			'event' => (string)$trigger['event'],
			'register' => (string)$trigger['register'],
			'schema_slug' => (string)$trigger['schema'],
		];
	}//end triggerRow()

	/**
	 * Insert one trigger row.
	 *
	 * @param array<string, string> $row     The flow uuid, event, register and schema slug, by column.
	 * @param bool                  $enabled Whether the flow is enabled.
	 *
	 * @return void
	 */
	private function insertRow(array $row, bool $enabled): void {
		$qb = $this->db->getQueryBuilder();
		$values = array_map(static fn (string $value): string => $qb->createNamedParameter($value), $row);
		$values['enabled'] = $qb->createNamedParameter($enabled, IQueryBuilder::PARAM_BOOL);
		$qb->insert(self::TABLE)->values($values);
		$qb->executeStatement();
	}//end insertRow()

	/**
	 * Drop one flow's trigger rows.
	 *
	 * @param string $flowUuid The flow's UUID.
	 *
	 * @return int The number of rows removed.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-trigger-matching-must-not-scale-with-the-number-of-flows
	 */
	public function deleteFor(string $flowUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('flow_uuid', $qb->createNamedParameter($flowUuid)));

		return $qb->executeStatement();
	}//end deleteFor()
}//end class
