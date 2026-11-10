<?php

/**
 * Which authorization caches a schema or register change evicts.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Listener\AuthorizationCacheInvalidationListener;
use OCA\OpenRegister\Service\Object\PermissionCacheInterface;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A schema change evicts that schema's inherit-from-public entry and the verdict memo; a
 * register change evicts every schema; anything else evicts nothing.
 *
 * @covers \OCA\OpenRegister\Listener\AuthorizationCacheInvalidationListener
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Event\AbstractRegisterEvent
 * @uses \OCA\OpenRegister\Event\AbstractSchemaEvent
 * @uses \OCA\OpenRegister\Event\RegisterUpdatedEvent
 * @uses \OCA\OpenRegister\Event\SchemaUpdatedEvent
 */
final class AuthorizationCacheEvictionScopeTest extends TestCase {

	private PermissionCacheInterface&MockObject $cache;

	/**
	 * Recorded cache calls.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * A cache that records what is cleared.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->cache = $this->createMock(PermissionCacheInterface::class);
		$this->cache->method('clearInheritFromPublicCache')->willReturnCallback(function (?int $schemaId = null): void {
			$this->calls[] = 'inherit:' . ($schemaId ?? 'all');
		});
		$this->cache->method('clearPermissionCache')->willReturnCallback(function (): void {
			$this->calls[] = 'verdicts';
		});
	}//end setUp()

	/**
	 * Handle an event and return what was cleared.
	 *
	 * @param Event $event The event.
	 *
	 * @return list<string> The cleared caches.
	 */
	private function cleared(Event $event): array {
		$this->calls = [];
		(new AuthorizationCacheInvalidationListener(permissionHandler: $this->cache))->handle($event);
		return $this->calls;
	}//end cleared()

	/**
	 * A schema of the given id.
	 *
	 * @param int $id The id.
	 *
	 * @return Schema The schema.
	 */
	private function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		return $schema;
	}//end schema()

	/**
	 * Schema update and delete evict only that schema, plus the verdict memo.
	 *
	 * @return void
	 */
	public function testASchemaChangeEvictsThatSchema(): void {
		$this->assertSame(['inherit:7', 'verdicts'], $this->cleared(new SchemaUpdatedEvent($this->schema(id: 7), $this->schema(id: 6))));
		$this->assertSame(['inherit:9', 'verdicts'], $this->cleared(new SchemaDeletedEvent($this->schema(id: 9))));
	}//end testASchemaChangeEvictsThatSchema()

	/**
	 * Register update and delete evict every schema, plus the verdict memo.
	 *
	 * @return void
	 */
	public function testARegisterChangeEvictsEverySchema(): void {
		$this->assertSame(['inherit:all', 'verdicts'], $this->cleared(new RegisterUpdatedEvent(new Register(), new Register())));
		$this->assertSame(['inherit:all', 'verdicts'], $this->cleared(new RegisterDeletedEvent(new Register())));
	}//end testARegisterChangeEvictsEverySchema()

	/**
	 * Any other event evicts nothing.
	 *
	 * @return void
	 */
	public function testOtherEventsEvictNothing(): void {
		$this->assertSame([], $this->cleared(new Event()));
	}//end testOtherEventsEvictNothing()
}//end class
