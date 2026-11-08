<?php

declare(strict_types=1);

/**
 * GetObject single-read golden (characterisation) test
 *
 * Pins, on the code as it stood before the D06 complexity refactor, where a
 * single read goes (an object source or the magic table), with which
 * arguments, whether it is registered on the audit trail, and what a missing
 * object source answers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Interaction\ReadHistoryService;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\GetObjectSources;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Golden routes of GetObject::find() and GetObject::findSilent().
 */
class GetObjectFindGoldenTest extends TestCase {
	/** @var list<array> Every call made to the mapper, the provider or the read history. */
	private array $calls = [];

	/** @var bool Whether the read history answers with an audit row. */
	private bool $auditRow = true;

	/**
	 * Build a GetObject over doubles that record every call.
	 *
	 * @return GetObject
	 */
	private function getObject(): GetObject {
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('find')->willReturnCallback(
			function (...$args): ObjectEntity {
				$this->calls[] = ['table', $args[0], $args[1]?->getId(), $args[2]?->getId(), $args[3], $args[4], $args[5]];
				$row = new ObjectEntity();
				$row->setUuid('t-1');
				$row->setObject(['a' => 1]);
				return $row;
			}
		);

		$readHistory = $this->createMock(ReadHistoryService::class);
		$readHistory->method('registerAuditRead')->willReturnCallback(
			function (ObjectEntity $object, bool $audit = true): ?AuditTrail {
				$this->calls[] = ['audit', $object->getUuid(), $audit];
				if ($this->auditRow === false) {
					return null;
				}

				$log = new AuditTrail();
				$log->setAction('read');
				return $log;
			}
		);

		$calls = &$this->calls;
		$provider = static function (bool $enabled) use (&$calls): object {
			return new class($enabled, $calls) implements ObjectSourceProvider {
				public function __construct(private bool $enabled, private array &$calls) {
				}

				public function getId(): string {
					return 'live';
				}

				public function find(Register $register, Schema $schema, string $id, array $config=[]): ?ObjectEntity {
					$this->calls[] = ['source', $register->getId(), $schema->getId(), $id, $config];
					if ($id === 'missing') {
						return null;
					}

					$row = new ObjectEntity();
					$row->setUuid('v-' . $id);
					return $row;
				}

				public function count(Register $register, Schema $schema, array $query=[], array $config=[]): int {
					return 0;
				}

				public function isEnabled(): bool {
					return $this->enabled;
				}

				public function findAll(Register $register, Schema $schema, array $query=[], array $config=[]): array {
					return [];
				}
			};
		};
		$registry = $this->createMock(ObjectSourceRegistry::class);
		$registry->method('get')->willReturnCallback(
			static fn (string $name): ?object => match ($name) {
				'live' => $provider(true),
				'off' => $provider(false),
				default => null,
			}
		);

		return new GetObject(
			objectMapper: $mapper,
			auditTrailMapper: $this->createMock(AuditTrailMapperInterface::class),
			readHistory: $readHistory,
			sources: new GetObjectSources(objectSourceRegistry: $registry, logger: new NullLogger())
		);
	}//end getObject()

	/**
	 * A schema, optionally served from an object source.
	 *
	 * @param array|null $source The object-source declaration.
	 *
	 * @return Schema
	 */
	private static function schema(?array $source): Schema {
		$schema = new Schema();
		$schema->setId(4);
		$schema->setSlug('s');
		if ($source !== null) {
			$schema->setConfiguration(['x-openregister-object-source' => $source]);
		}

		return $schema;
	}//end schema()

	/**
	 * A register with id 2.
	 *
	 * @return Register
	 */
	private static function register(): Register {
		$register = new Register();
		$register->setId(2);
		return $register;
	}//end register()

	/**
	 * Run one read, returning [uuid or exception message, calls, lastLog].
	 *
	 * @param callable $read The read.
	 *
	 * @return array
	 */
	private function route(callable $read): array {
		$this->calls = [];
		try {
			$object = $read($this->getObject());
			return [$object->getUuid(), $this->calls, $object->getLastLog()];
		} catch (DoesNotExistException $e) {
			return ['404: ' . $e->getMessage(), $this->calls, null];
		}
	}//end route()

	/**
	 * Every route of find().
	 *
	 * @return void
	 */
	public function testFindRoutes(): void {
		$live = self::schema(['provider' => 'live', 'config' => ['k' => 'v']]);

		$this->assertSame(
			['v-x', [['source', 2, 4, 'x', ['k' => 'v']]], null],
			$this->route(fn (GetObject $g) => $g->find(id: 'x', register: self::register(), schema: $live))
		);
		$this->assertSame(
			['404: Object missing not found', [['source', 2, 4, 'missing', ['k' => 'v']]], null],
			$this->route(fn (GetObject $g) => $g->find(id: 'missing', register: self::register(), schema: $live))
		);
		$this->assertSame(
			['404: Object x not found', [], null],
			$this->route(fn (GetObject $g) => $g->find(id: 'x', register: self::register(), schema: self::schema(['provider' => 'off'])))
		);
		$this->assertSame(
			['404: Object x not found', [], null],
			$this->route(fn (GetObject $g) => $g->find(id: 'x', register: self::register(), schema: self::schema(['provider' => 'gone'])))
		);
		$this->assertSame(
			['v-y', [['source', 2, 4, 'y', []]], null],
			$this->route(fn (GetObject $g) => $g->find(id: 'y', register: self::register(), schema: self::schema(['provider' => 'live'])))
		);

		$table = $this->route(
			fn (GetObject $g) => $g->find(id: 'x', register: self::register(), schema: self::schema(null), files: true, _rbac: false, _multitenancy: false, _audit: false)
		);
		$this->assertSame('t-1', $table[0]);
		$this->assertSame([['table', 'x', 2, 4, false, false, false], ['audit', 't-1', false]], $table[1]);
		$this->assertSame('read', $table[2]['action']);

		$this->auditRow = false;
		$this->assertSame(
			['t-1', [['table', 'x', null, null, false, true, true], ['audit', 't-1', true]], null],
			$this->route(fn (GetObject $g) => $g->find(id: 'x'))
		);
	}//end testFindRoutes()

	/**
	 * Every route of findSilent(): the same reads, never an audit call.
	 *
	 * @return void
	 */
	public function testFindSilentRoutes(): void {
		$live = self::schema(['provider' => 'live', 'config' => ['k' => 'v']]);

		$this->assertSame(
			['v-x', [['source', 2, 4, 'x', ['k' => 'v']]], null],
			$this->route(fn (GetObject $g) => $g->findSilent(id: 'x', register: self::register(), schema: $live))
		);
		$this->assertSame(
			['404: Object missing not found', [['source', 2, 4, 'missing', ['k' => 'v']]], null],
			$this->route(fn (GetObject $g) => $g->findSilent(id: 'missing', register: self::register(), schema: $live))
		);
		$this->assertSame(
			['404: Object x not found', [], null],
			$this->route(fn (GetObject $g) => $g->findSilent(id: 'x', register: self::register(), schema: self::schema(['provider' => 'off'])))
		);
		$this->assertSame(
			['t-1', [['table', 'x', 2, 4, false, false, true]], null],
			$this->route(fn (GetObject $g) => $g->findSilent(id: 'x', register: self::register(), schema: self::schema(null), files: true, _rbac: false))
		);
		$this->assertSame(
			['t-1', [['table', 'x', null, null, false, true, true]], null],
			$this->route(fn (GetObject $g) => $g->findSilent(id: 'x'))
		);
	}//end testFindSilentRoutes()
}//end class
