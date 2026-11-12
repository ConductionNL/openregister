<?php

/**
 * Characterisation of three mapper methods: TaskBlockerMapper::openBlockerUuids(),
 * ConfigurationValueMapper::findAtAddress() and PortalTaskDeliveryMapper::insert(),
 * pinned call for call with their bound values and results.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ConfigurationValueMapper;
use OCA\OpenRegister\Db\PortalTaskDelivery;
use OCA\OpenRegister\Db\PortalTaskDeliveryMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskBlockerMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Golden queries of three mapper methods.
 *
 * @covers \OCA\OpenRegister\Db\TaskBlockerMapper
 * @covers \OCA\OpenRegister\Db\ConfigurationValueMapper
 * @covers \OCA\OpenRegister\Db\PortalTaskDeliveryMapper
 */
final class MapperStepsTwoGoldenTest extends TestCase {
	use RecordsQueries;

	/**
	 * Open blockers: the distinct non-empty uuids that are not terminal; none is no query.
	 *
	 * @return void
	 */
	public function testOpenBlockerUuids(): void {
		$mapper = new TaskBlockerMapper($this->recordingDb());
		$this->assertSame([], $mapper->openBlockerUuids(['', '']));
		$this->assertSame([], $this->queries);

		$this->results[] = [['uuid' => 'b'], ['uuid' => 'a']];
		$this->assertSame(['b', 'a'], $mapper->openBlockerUuids(['a', '', 'b', 'a']));
		$this->assertSame(
			'select(uuid) from(openregister_tasks) where(in(uuid, :p0)) andWhere(eq(is_terminal, :p1)) executeQuery()',
			$this->sql()
		);
		$this->assertSame([[['a', 'b'], IQueryBuilder::PARAM_STR_ARRAY], [false, IQueryBuilder::PARAM_BOOL]], array_values($this->params));
	}//end testOpenBlockerUuids()

	/**
	 * A configuration value at an address: its layer, key and layer reference (or none).
	 *
	 * @return void
	 */
	public function testFindAtAddress(): void {
		$mapper = new ConfigurationValueMapper($this->recordingDb());
		$this->results[] = [['id' => 4, 'config_key' => 'k']];

		$this->assertSame(4, $mapper->findAtAddress('tenant', 'org-1', 'k')?->getId());
		$this->assertNull($mapper->findAtAddress('instance', null, 'k'));

		$this->assertSame(
			'select(*) from(openregister_config_values) where(eq(layer, :p0)) andWhere(eq(config_key, :p1)) setMaxResults(1) andWhere(eq(layer_ref, :p2)) executeQuery()',
			$this->sql(0)
		);
		$this->assertSame(
			'select(*) from(openregister_config_values) where(eq(layer, :p3)) andWhere(eq(config_key, :p4)) setMaxResults(1) andWhere(isNull(layer_ref)) executeQuery()',
			$this->sql(1)
		);
		$this->assertSame(['tenant', 'k', 'org-1', 'instance', 'k'], $this->boundValues());
	}//end testFindAtAddress()

	/**
	 * A new delivery gets a uuid, the requested state and its times, in that column order.
	 *
	 * @return void
	 */
	public function testDeliveryInsertFillsDefaults(): void {
		$mapper = new PortalTaskDeliveryMapper($this->recordingDb());
		$delivery = new PortalTaskDelivery();
		$delivery->setTaskUuid('t-1');

		$saved = $mapper->insert($delivery);

		$this->assertSame(36, strlen((string) $saved->getUuid()));
		$this->assertSame(PortalTaskDelivery::STATE_REQUESTED, $saved->getState());
		$this->assertInstanceOf(DateTime::class, $saved->getRequestedAt());
		$this->assertSame($saved->getRequestedAt(), $saved->getCreated());
		$this->assertStringStartsWith(
			'insert(' . PortalTaskDeliveryMapper::TABLE . ') setValue(task_uuid, :p0) setValue(uuid, :p1) setValue(state, :p2) setValue(requested_at, :p3) setValue(created, :p4)',
			$this->sql()
		);
	}//end testDeliveryInsertFillsDefaults()

	/**
	 * A delivery's own uuid, state and request time are kept; created is always now.
	 *
	 * @return void
	 */
	public function testDeliveryInsertKeepsWhatIsSet(): void {
		$requested = new DateTime('2026-01-02T03:04:05+00:00');
		$delivery = new PortalTaskDelivery();
		$delivery->setUuid('d-1');
		$delivery->setState('delivered');
		$delivery->setRequestedAt($requested);

		$saved = (new PortalTaskDeliveryMapper($this->recordingDb()))->insert($delivery);

		$this->assertSame('d-1', $saved->getUuid());
		$this->assertSame('delivered', $saved->getState());
		$this->assertSame($requested, $saved->getRequestedAt());
		$this->assertNotSame($requested, $saved->getCreated());
	}//end testDeliveryInsertKeepsWhatIsSet()

	/**
	 * Any other entity is refused before a query.
	 *
	 * @return void
	 */
	public function testDeliveryInsertRefusesOtherEntities(): void {
		try {
			(new PortalTaskDeliveryMapper($this->recordingDb()))->insert(new Task());
			$this->fail('A foreign entity was stored.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('PortalTaskDeliveryMapper stores PortalTaskDelivery rows only.', $e->getMessage());
		}

		$this->assertSame([], $this->queries);
	}//end testDeliveryInsertRefusesOtherEntities()
}//end class
