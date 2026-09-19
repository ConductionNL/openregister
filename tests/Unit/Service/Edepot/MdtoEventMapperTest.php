<?php

declare(strict_types=1);

/**
 * MdtoEventMapper Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Service\Edepot;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\MdtoEventMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Test class for MdtoEventMapper.
 */
class MdtoEventMapperTest extends TestCase {
	private AuditTrailMapper&MockObject $auditTrailMapper;
	private LoggerInterface&MockObject $logger;
	private MdtoEventMapper $mapper;

	protected function setUp(): void {
		parent::setUp();

		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->mapper = new MdtoEventMapper($this->auditTrailMapper, $this->logger);
	}

	/**
	 * Rows are mapped to EventTypeLijst labels and returned oldest first.
	 */
	public function testRowsMapToEventTypeLabelsOldestFirst(): void {
		$this->auditTrailMapper->method('findAll')->willReturnCallback(
			function (?int $limit, ?int $offset, ?array $filters): array {
				if (($filters['action'] ?? '') === 'create') {
					return [$this->auditRow(id: 1, action: 'create', created: '2026-01-01 09:00:00')];
				}

				return [
					$this->auditRow(id: 3, action: 'archival.transferred', created: '2026-03-01 09:00:00'),
					$this->auditRow(id: 2, action: 'update', created: '2026-02-01 09:00:00'),
					$this->auditRow(id: 1, action: 'create', created: '2026-01-01 09:00:00'),
				];
			}
		);

		$events = $this->mapper->forObject($this->objectEntity(uuid: 'obj-1'));

		$this->assertSame(['Creatie', 'Wijziging', 'Overbrenging'], array_column($events, 'type'));
		$this->assertSame('2026-01-01T09:00:00+00:00', $events[0]['time']);
		$this->assertSame('jdoe', $events[0]['actorId']);
		$this->assertSame('Jane Doe', $events[0]['actorName']);
	}

	/**
	 * The allow-list is applied in the query, so reads never reach the export.
	 */
	public function testOnlyAllowListedActionsAreRequested(): void {
		$seen = [];
		$this->auditTrailMapper->method('findAll')->willReturnCallback(
			function (?int $limit, ?int $offset, ?array $filters) use (&$seen): array {
				$seen[] = ($filters['action'] ?? '');
				return [];
			}
		);

		$this->mapper->forObject($this->objectEntity(uuid: 'obj-1'));

		$requested = explode(',', $seen[0]);
		$this->assertSame(array_keys(MdtoEventMapper::EVENT_TYPE_BY_ACTION), $requested);
		$this->assertNotContains('read', $requested);
		$this->assertNotContains('list', $requested);
		$this->assertNotContains('delete', $requested);
	}

	/**
	 * An action nobody mapped is dropped rather than exported under a guess.
	 */
	public function testUnmappedActionsAreDropped(): void {
		$this->auditTrailMapper->method('findAll')->willReturnCallback(
			function (?int $limit, ?int $offset, ?array $filters): array {
				if (($filters['action'] ?? '') === 'create') {
					return [];
				}

				return [
					$this->auditRow(id: 2, action: 'update', created: '2026-02-01 09:00:00'),
				];
			}
		);

		$events = $this->mapper->forObject($this->objectEntity(uuid: 'obj-1'));

		$this->assertSame(['Wijziging'], array_column($events, 'type'));
	}

	/**
	 * The emission is bounded, and truncation keeps the creation event.
	 */
	public function testEmissionIsBoundedAndKeepsTheCreationEvent(): void {
		$this->auditTrailMapper->method('findAll')->willReturnCallback(
			function (?int $limit, ?int $offset, ?array $filters): array {
				if (($filters['action'] ?? '') === 'create') {
					return [$this->auditRow(id: 1, action: 'create', created: '2020-01-01 09:00:00')];
				}

				$rows = [];
				for ($i = 0; $i < MdtoEventMapper::MAX_EVENTS; $i++) {
					$rows[] = $this->auditRow(
						id: (100 + $i),
						action: 'update',
						created: sprintf('2026-01-%02d 09:00:00', ($i + 1))
					);
				}

				return $rows;
			}
		);

		$events = $this->mapper->forObject($this->objectEntity(uuid: 'obj-1'));

		$this->assertCount(MdtoEventMapper::MAX_EVENTS, $events);
		$this->assertSame('Creatie', $events[0]['type']);
		$this->assertSame(
			MdtoEventMapper::MAX_EVENTS - 1,
			count(array_filter($events, static fn (array $e): bool => $e['type'] === 'Wijziging'))
		);
	}

	/**
	 * An object with no uuid produces no events and no query.
	 */
	public function testObjectWithoutUuidProducesNoEvents(): void {
		$this->auditTrailMapper->expects($this->never())->method('findAll');

		$this->assertSame([], $this->mapper->forObject($this->objectEntity(uuid: null)));
	}

	/**
	 * An unreadable audit trail degrades the export rather than failing it.
	 */
	public function testUnreadableAuditTrailYieldsNoEventsAndWarns(): void {
		$this->auditTrailMapper->method('findAll')->willThrowException(new RuntimeException('table gone'));
		$this->logger->expects($this->once())->method('warning');

		$this->assertSame([], $this->mapper->forObject($this->objectEntity(uuid: 'obj-1')));
	}

	/**
	 * Build an AuditTrail row.
	 *
	 * @param int $id The row id.
	 * @param string $action The audit action.
	 * @param string $created The creation timestamp.
	 *
	 * @return AuditTrail The row.
	 */
	private function auditRow(int $id, string $action, string $created): AuditTrail {
		$row = new AuditTrail();
		$row->setId($id);
		$row->setAction($action);
		$row->setCreated(new DateTime($created . ' UTC'));
		$row->setUser('jdoe');
		$row->setUserName('Jane Doe');

		return $row;
	}

	/**
	 * Build a mock ObjectEntity.
	 *
	 * @param string|null $uuid The object uuid.
	 *
	 * @return ObjectEntity&MockObject The mock.
	 */
	private function objectEntity(?string $uuid): ObjectEntity&MockObject {
		$object = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['getUuid'])
			->getMock();
		$object->method('getUuid')->willReturn($uuid);

		return $object;
	}
}
