<?php

/**
 * SchemaMapper::update() event tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\AppFramework\Db\Entity;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Drives the real SchemaMapper::update() and checks which saves dispatch a
 * SchemaUpdatedEvent.
 *
 * Only the database and the access checks are stood in for: the stored row
 * is mapped through the real Schema entity, as findEntity() would, and the
 * dispatched event is the real SchemaUpdatedEvent.
 *
 * @spec openspec/changes/events-at-the-level-of-change/specs/event-driven-architecture/spec.md#requirement-a-schema-save-that-changes-nothing-publishes-nothing
 */
class SchemaMapperUpdateEventTest extends TestCase {
	/**
	 * The schema row as the database holds it.
	 *
	 * @var array<string, mixed>
	 */
	private const STORED_ROW = [
		'id' => '30',
		'uuid' => 'a0b1c2d3-0000-4000-8000-000000000030',
		'slug' => 'lead',
		'title' => 'Lead',
		'version' => '1.4.0',
		'description' => 'A sales lead.',
		'properties' => '{"title":{"type":"string","translatable":true},"value":{"type":"number"}}',
		'required' => '["title"]',
		'source' => 'internal',
		'owner' => 'pipelinq',
		'application' => 'pipelinq',
		'updated' => '2026-10-06 11:38:31',
		'created' => '2026-10-06 11:38:31',
		// What the mapper derived on the save that stored this row.
		'configuration' => '{"objectNameField":"title"}',
		'facets' => '{"@self":{"register":{"type":"terms"},"schema":{"type":"terms"},"created":{"type":"date_histogram","interval":"month"},"updated":{"type":"date_histogram","interval":"month"},"published":{"type":"date_histogram","interval":"month"},"owner":{"type":"terms"}},"object_fields":[]}',
	];

	/**
	 * Events the mapper dispatched.
	 *
	 * @var array<int, object>
	 */
	private array $dispatched = [];

	/**
	 * The stored row, for the anonymous mapper.
	 *
	 * @return array<string, mixed> The row.
	 */
	public static function storedRow(): array {
		return self::STORED_ROW;
	}//end storedRow()

	/**
	 * Build a mapper whose database answers with STORED_ROW.
	 *
	 * @return SchemaMapper The mapper under test.
	 */
	private function mapper(): SchemaMapper {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('id = :id');

		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'update', 'set', 'andWhere'] as $chain) {
			$qb->method($chain)->willReturnSelf();
		}

		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				$this->dispatched[] = $event;
			}
		);

		return new class(
			$db,
			$dispatcher,
			$this->createMock(PropertyValidatorHandler::class),
			$this->createMock(OrganisationMapper::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class)
		) extends SchemaMapper {
			/**
			 * Return the stored row mapped through the real entity.
			 *
			 * @param IQueryBuilder $query Ignored.
			 *
			 * @return Entity The stored schema.
			 */
			protected function findEntity(IQueryBuilder $query): Entity {
				return Schema::fromRow(SchemaMapperUpdateEventTest::storedRow());
			}

			/**
			 * Access is not under test.
			 *
			 * @param string $action Ignored.
			 * @param string $entityType Ignored.
			 *
			 * @return void
			 */
			protected function verifyRbacPermission(string $action, string $entityType): void {
			}

			/**
			 * Access is not under test.
			 *
			 * @param Entity $entity Ignored.
			 *
			 * @return void
			 */
			protected function verifyOrganisationAccess(Entity $entity): void {
			}
		};
	}//end mapper()

	/**
	 * The schema an app re-import hands to update(): every field written again.
	 *
	 * @return Schema The re-imported schema.
	 */
	private function reimported(): Schema {
		$schema = Schema::fromRow(self::STORED_ROW);
		$schema->setTitle('Lead');
		$schema->setDescription('A sales lead.');
		$schema->setProperties(['title' => ['type' => 'string', 'translatable' => true], 'value' => ['type' => 'number']]);
		$schema->setRequired(['title']);

		return $schema;
	}//end reimported()

	/**
	 * An identical re-import dispatches nothing and counts nothing.
	 *
	 * @return void
	 */
	public function testUnchangedSaveDispatchesNoEvent(): void {
		$mapper = $this->mapper();
		$mapper->update($this->reimported());

		$this->assertSame([], $this->dispatched);
		$this->assertSame(0, $mapper->updateEventCount(schemaId: 30));
	}//end testUnchangedSaveDispatchesNoEvent()

	/**
	 * A new property is a change: one real SchemaUpdatedEvent, counted.
	 *
	 * @return void
	 */
	public function testAddedPropertyDispatchesOneEvent(): void {
		$schema = $this->reimported();
		$schema->setProperties(
			['title' => ['type' => 'string', 'translatable' => true], 'value' => ['type' => 'number'], 'stage' => ['type' => 'string']]
		);

		$mapper = $this->mapper();
		$mapper->update($schema);

		$this->assertCount(1, $this->dispatched);
		$event = $this->dispatched[0];
		$this->assertInstanceOf(SchemaUpdatedEvent::class, $event);
		$this->assertArrayHasKey('stage', $event->getNewSchema()->getProperties());
		$this->assertArrayNotHasKey('stage', $event->getOldSchema()->getProperties());
		$this->assertSame(1, $mapper->updateEventCount(schemaId: 30));
	}//end testAddedPropertyDispatchesOneEvent()

	/**
	 * A renamed schema is a change.
	 *
	 * @return void
	 */
	public function testTitleChangeDispatchesOneEvent(): void {
		$schema = $this->reimported();
		$schema->setTitle('Sales lead');

		$this->mapper()->update($schema);

		$this->assertCount(1, $this->dispatched);
	}//end testTitleChangeDispatchesOneEvent()
}//end class
