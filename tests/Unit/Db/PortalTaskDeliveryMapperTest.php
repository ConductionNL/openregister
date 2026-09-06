<?php

/**
 * The delivery mapper's query vocabulary, walked without a database: the
 * stamps an insert adds, the predicates each finder uses, and the two state
 * moves.
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

use OCA\OpenRegister\Db\PortalTaskDelivery;
use OCA\OpenRegister\Db\PortalTaskDeliveryMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see PortalTaskDeliveryMapper}.
 *
 * @covers \OCA\OpenRegister\Db\PortalTaskDeliveryMapper
 * @covers \OCA\OpenRegister\Db\PortalTaskDelivery
 */
class PortalTaskDeliveryMapperTest extends TestCase {
	use FluentQueryBuilderTrait;

	/**
	 * A stored row.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(): array {
		return [
			'id' => 5,
			'uuid' => 'd-5',
			'task_uuid' => 't-1',
			'party_reference' => 'party:bsn-1',
			'channel' => 'mail',
			'kind' => 'ask',
			'state' => 'requested',
			'message' => '{"title":"x"}',
			'requested_at' => '2026-09-01 10:00:00',
		];
	}//end row()

	/**
	 * An insert stamps uuid, state and requestedAt when the caller left them out.
	 *
	 * @return void
	 */
	public function testInsertStampsUuidStateAndRequestedAt(): void {
		$mapper = new PortalTaskDeliveryMapper(db: $this->connectionWith());
		$row = new PortalTaskDelivery();
		$row->setTaskUuid('t-1');
		$row->setChannel(PortalTaskDelivery::CHANNEL_MAIL);
		$row->setKind(PortalTaskDelivery::KIND_ASK);

		$inserted = $mapper->insert($row);
		$this->assertNotEmpty($inserted->getUuid());
		$this->assertSame(PortalTaskDelivery::STATE_REQUESTED, $inserted->getState());
		$this->assertNotNull($inserted->getRequestedAt());
		$this->assertNotNull($inserted->getCreated());
		$this->assertTrue($this->saw('insert'));
	}//end testInsertStampsUuidStateAndRequestedAt()

	/**
	 * The finders predicate on uuid, task uuid and state, and map the row.
	 *
	 * @return void
	 */
	public function testFindersPredicateAndMap(): void {
		$mapper = new PortalTaskDeliveryMapper(db: $this->connectionWith(rows: [$this->row()]));
		$found = $mapper->findByUuid(uuid: 'd-5');
		$this->assertSame('t-1', $found->getTaskUuid());
		$this->assertSame(['title' => 'x'], $found->getMessage());
		$this->assertTrue($this->saw('expr.eq', 'uuid'));

		$mapper->findForTask(taskUuid: 't-1');
		$this->assertTrue($this->saw('expr.eq', 'task_uuid'));

		$mapper->findPending(limit: 5);
		$this->assertTrue($this->saw('expr.eq', 'state'));
		$this->assertTrue($this->saw('setMaxResults', 5));

		$grouped = $mapper->findForTasks(taskUuids: ['t-1', 't-1', '']);
		$this->assertTrue($this->saw('expr.in', 'task_uuid'));
		$this->assertArrayHasKey('t-1', $grouped);
		$this->assertSame([], $mapper->findForTasks(taskUuids: []));

		$this->expectException(DoesNotExistException::class);
		(new PortalTaskDeliveryMapper(db: $this->connectionWith(rows: [])))->findByUuid(uuid: 'ghost');
	}//end testFindersPredicateAndMap()

	/**
	 * The two state moves set what they say and nothing else.
	 *
	 * @return void
	 */
	public function testTheStateMovesSetWhatTheySay(): void {
		$mapper = new PortalTaskDeliveryMapper(db: $this->connectionWith());
		$row = new PortalTaskDelivery();
		$row->setId(5);
		$row->setUuid('d-5');
		$row->setState(PortalTaskDelivery::STATE_REQUESTED);
		$row->setError('earlier');

		$delivered = $mapper->markDelivered(delivery: $row);
		$this->assertSame(PortalTaskDelivery::STATE_DELIVERED, $delivered->getState());
		$this->assertNotNull($delivered->getDeliveredAt());
		$this->assertNull($delivered->getError());

		$failed = $mapper->markFailed(delivery: $row, error: str_repeat('x', 1200));
		$this->assertSame(PortalTaskDelivery::STATE_FAILED, $failed->getState());
		$this->assertSame(1000, strlen((string)$failed->getError()), 'the error is bounded');
		$this->assertTrue($this->saw('update'));
	}//end testTheStateMovesSetWhatTheySay()
}//end class
