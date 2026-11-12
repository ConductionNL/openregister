<?php

/**
 * What SaveObject hands a writable object-source provider, and when it refuses.
 *
 * Pins the v1 read-only refusal without a resolved register, an empty id
 * taken as a create, the stored row read before an update and recorded as the
 * audit's old side, and the annotation's `config` reaching every provider call.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSaveCall;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSaveFlags;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use OCA\OpenRegister\Service\ObjectSource\SourcedTable;
use OCA\OpenRegister\Service\ObjectSource\WritableObjectSourceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;

/**
 * Object-source delegation, golden.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject
 */
final class SaveObjectObjectSourceDelegationTest extends TestCase {

	/**
	 * Every provider call, as [operation, id, config].
	 *
	 * @var array<int, array{0: string, 1: string|null, 2: array}>
	 */
	public array $calls = [];

	/**
	 * Every audit row, as [old uuid, new uuid, action].
	 *
	 * @var array<int, array{0: string|null, 1: string|null, 2: string}>
	 */
	private array $rows = [];

	/**
	 * A writable provider that records into this test and finds a stored `old-<id>` row.
	 *
	 * @return WritableObjectSourceProvider The provider.
	 */
	private function provider(): WritableObjectSourceProvider {
		return new class ($this) implements WritableObjectSourceProvider {
			/**
			 * @param SaveObjectObjectSourceDelegationTest $test The recording test.
			 */
			public function __construct(private readonly SaveObjectObjectSourceDelegationTest $test) {
			}

			/**
			 * @return string The id.
			 */
			public function getId(): string {
				return 'dbal-source';
			}

			/**
			 * @return bool True.
			 */
			public function isEnabled(): bool {
				return true;
			}

			/**
			 * @param SourcedTable $source The table.
			 * @param string       $id     The id.
			 *
			 * @return ObjectEntity|null The stored row.
			 */
			public function find(SourcedTable $source, string $id): ?ObjectEntity {
				$this->test->calls[] = ['find', $id, $source->config];
				$old = new ObjectEntity();
				$old->setUuid('old-' . $id);

				return $old;
			}

			/**
			 * @param SourcedTable $source The table.
			 * @param array        $query  The query.
			 *
			 * @return array Nothing.
			 */
			public function findAll(SourcedTable $source, array $query = []): array {
				return [];
			}

			/**
			 * @param SourcedTable $source The table.
			 * @param array        $query  The query.
			 *
			 * @return int Zero.
			 */
			public function count(SourcedTable $source, array $query = []): int {
				return 0;
			}

			/**
			 * @param SourcedTable $source The table.
			 * @param array        $data   The data.
			 *
			 * @return ObjectEntity The created row.
			 */
			public function insert(SourcedTable $source, array $data): ObjectEntity {
				$this->test->calls[] = ['insert', null, $source->config];
				$entity = new ObjectEntity();
				$entity->setUuid('new-1');

				return $entity;
			}

			/**
			 * @param SourcedTable $source The table.
			 * @param string       $id     The id.
			 * @param array        $data   The data.
			 *
			 * @return ObjectEntity The updated row.
			 */
			public function update(SourcedTable $source, string $id, array $data): ObjectEntity {
				$this->test->calls[] = ['update', $id, $source->config];
				$entity = new ObjectEntity();
				$entity->setUuid($id);

				return $entity;
			}

			/**
			 * @param SourcedTable $source The table.
			 * @param string       $id     The id.
			 *
			 * @return bool True.
			 */
			public function remove(SourcedTable $source, string $id): bool {
				return true;
			}
		};
	}//end provider()

	/**
	 * Delegate one write; answer the result uuid or the refusal message.
	 *
	 * @param string|null   $uuid     The id, null or empty for a create.
	 * @param Register|null $register The resolved register.
	 *
	 * @return string The result.
	 */
	private function delegate(?string $uuid, ?Register $register): string {
		$registry = new ObjectSourceRegistry(logger: new NullLogger());
		$registry->addProvider($this->provider());
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('createAuditTrail')->willReturnCallback(
			function (?ObjectEntity $old, ?ObjectEntity $new, string $action) {
				$this->rows[] = [$old?->getUuid(), $new?->getUuid(), $action];

				return new \OCA\OpenRegister\Db\AuditTrail();
			}
		);
		$reflection = new ReflectionClass(SaveObject::class);
		$service = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('objectSourceRegistry')->setValue($service, $registry);
		$reflection->getProperty('logger')->setValue($service, new NullLogger());
		$reflection->getProperty('auditTrailMapper')->setValue($service, $audit);

		$schema = new Schema();
		$schema->setSlug('permits');
		$call = new ObjectSaveCall();
		$call->register = $register;
		$call->schema = $schema;
		$call->data = ['status' => 'submitted'];
		$call->uuid = $uuid;
		$call->flags = new ObjectSaveFlags();

		try {
			$result = $reflection->getMethod('delegateObjectSourceWrite')->invoke($service, $call, ['provider' => 'dbal-source', 'readOnly' => false, 'config' => ['table' => 'permits']]);
		} catch (RuntimeException $e) {
			return $e->getMessage();
		}

		return (string)$result->getUuid();
	}//end delegate()

	/**
	 * Without a resolved register the write is refused before the provider is called.
	 *
	 * @return void
	 */
	public function testAnUnresolvedRegisterIsRefused(): void {
		$this->assertSame(
			'Schema "permits" is a read-only projection of object-source provider "dbal-source"; writes are not allowed.',
			$this->delegate(uuid: null, register: null)
		);
		$this->assertSame([[], []], [$this->calls, $this->rows]);
	}//end testAnUnresolvedRegisterIsRefused()

	/**
	 * An empty id is a create; the config reaches the provider.
	 *
	 * @return void
	 */
	public function testAnEmptyIdIsACreate(): void {
		$this->assertSame('new-1', $this->delegate(uuid: '', register: new Register()));
		$this->assertSame([['insert', null, ['table' => 'permits']]], $this->calls);
		$this->assertSame([[null, 'new-1', 'create']], $this->rows);
	}//end testAnEmptyIdIsACreate()

	/**
	 * An update reads the stored row first and audits it as the old side.
	 *
	 * @return void
	 */
	public function testAnUpdateAuditsTheStoredRowAsOld(): void {
		$this->assertSame('r-7', $this->delegate(uuid: 'r-7', register: new Register()));
		$this->assertSame([['find', 'r-7', ['table' => 'permits']], ['update', 'r-7', ['table' => 'permits']]], $this->calls);
		$this->assertSame([['old-r-7', 'r-7', 'update']], $this->rows);
	}//end testAnUpdateAuditsTheStoredRowAsOld()
}//end class
