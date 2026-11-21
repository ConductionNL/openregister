<?php

/**
 * Golden: the audit row of a write delegated to an external object source.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\ObjectSource\SourcedTable;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSaveCall;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSaveFlags;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use OCA\OpenRegister\Service\ObjectSource\WritableObjectSourceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;

/**
 * Pins which audit row an object-source write records: the action, the
 * object before and after, none when silent, and that a failing audit write
 * never fails the write.
 */
final class SaveObjectObjectSourceAuditGoldenTest extends TestCase {

	/**
	 * Build a writable stub provider recording the operations it received.
	 *
	 * @return WritableObjectSourceProvider The stub.
	 */
	private function writableProvider(): WritableObjectSourceProvider {
		return new class implements WritableObjectSourceProvider {
			/**
			 * Operations received.
			 *
			 * @var array<int, string>
			 */
			public array $calls = [];

			/**
			 * The provider id.
			 *
			 * @return string The id.
			 */
			public function getId(): string {
				return 'dbal-source';
			}//end getId()

			/**
			 * Always enabled.
			 *
			 * @return bool True.
			 */
			public function isEnabled(): bool {
				return true;
			}//end isEnabled()

			/**
			 * Never finds (unused pre-read).
			 *
			 * @param Register $register The register.
			 * @param Schema $schema The schema.
			 * @param string $id The id.
			 * @param array $config The config.
			 *
			 * @return ObjectEntity|null Always null.
			 */
			public function find(SourcedTable $source, string $id): ?ObjectEntity {
				$this->calls[] = 'find';
				return null;
			}//end find()

			/**
			 * Unused.
			 *
			 * @param Register $register The register.
			 * @param Schema $schema The schema.
			 * @param array $query The query.
			 * @param array $config The config.
			 *
			 * @return array Always empty.
			 */
			public function findAll(SourcedTable $source, array $query = []): array {
				return [];
			}//end findAll()

			/**
			 * Unused.
			 *
			 * @param Register $register The register.
			 * @param Schema $schema The schema.
			 * @param array $query The query.
			 * @param array $config The config.
			 *
			 * @return int Zero.
			 */
			public function count(SourcedTable $source, array $query = []): int {
				return 0;
			}//end count()

			/**
			 * Record the insert and echo an entity.
			 *
			 * @param Register $register The register.
			 * @param Schema $schema The schema.
			 * @param array $data The data.
			 * @param array $config The config.
			 *
			 * @return ObjectEntity The created stub entity.
			 */
			public function insert(SourcedTable $source, array $data): ObjectEntity {
				$this->calls[] = 'insert';
				$entity = new ObjectEntity();
				$entity->setUuid('101');
				$entity->setObject($data);
				return $entity;
			}//end insert()

			/**
			 * Record the update and echo an entity.
			 *
			 * @param Register $register The register.
			 * @param Schema $schema The schema.
			 * @param string $id The id.
			 * @param array $data The data.
			 * @param array $config The config.
			 *
			 * @return ObjectEntity The updated stub entity.
			 */
			public function update(SourcedTable $source, string $id, array $data): ObjectEntity {
				$this->calls[] = 'update';
				$entity = new ObjectEntity();
				$entity->setUuid($id);
				$entity->setObject($data);
				return $entity;
			}//end update()

			/**
			 * Record the remove.
			 *
			 * @param Register $register The register.
			 * @param Schema $schema The schema.
			 * @param string $id The id.
			 * @param array $config The config.
			 *
			 * @return bool True.
			 */
			public function remove(SourcedTable $source, string $id): bool {
				$this->calls[] = 'remove';
				return true;
			}//end remove()
		};
	}//end writableProvider()

	/**
	 * Delegate one write and return what the audit trail was asked to record.
	 *
	 * @param string|null $uuid      The update id, or null for create.
	 * @param bool        $silent    Whether the write is silent.
	 * @param bool        $auditFails Whether the audit write throws.
	 *
	 * @return array{rows: list<array{0: string|null, 1: string|null, 2: string}>, result: string|null}
	 */
	private function audited(?string $uuid, bool $silent, bool $auditFails = false): array {
		$registry = new ObjectSourceRegistry(logger: new NullLogger());
		$registry->addProvider($this->writableProvider());

		$rows = [];
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('createAuditTrail')->willReturnCallback(
			function (?ObjectEntity $old, ?ObjectEntity $new, string $action) use (&$rows, $auditFails) {
				$rows[] = [$old?->getUuid(), $new?->getUuid(), $action];
				if ($auditFails === true) {
					throw new RuntimeException('audit down');
				}

				return new \OCA\OpenRegister\Db\AuditTrail();
			}
		);

		$reflection = new ReflectionClass(SaveObject::class);
		$service = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('objectSourceRegistry')->setValue($service, $registry);
		$reflection->getProperty('logger')->setValue($service, new NullLogger());
		$reflection->getProperty('auditTrailMapper')->setValue($service, $audit);

		$register = new Register();
		$register->setId(1);
		$schema = new Schema();
		$schema->setId(5);
		$schema->setSlug('permits');

		$call = new ObjectSaveCall();
		$call->register = $register;
		$call->schema = $schema;
		$call->data = ['status' => 'submitted'];
		$call->uuid = $uuid;
		$call->flags = new ObjectSaveFlags();
		$call->flags->silent = $silent;

		$result = $reflection->getMethod('delegateObjectSourceWrite')->invoke(
			$service,
			$call,
			['provider' => 'dbal-source', 'readOnly' => false, 'config' => []]
		);

		return ['rows' => $rows, 'result' => $result->getUuid()];
	}//end audited()

	public function testGolden(): void {
		$update = ['rows' => [[null, 'row-7', 'update']], 'result' => 'row-7'];
		$this->assertSame(
			[
				'create' => ['rows' => [[null, '101', 'create']], 'result' => '101'],
				'update' => $update,
				'silent create' => ['rows' => [], 'result' => '101'],
				'silent update' => ['rows' => [], 'result' => 'row-7'],
				'audit failure' => $update,
			],
			[
				'create' => $this->audited(uuid: null, silent: false),
				'update' => $this->audited(uuid: 'row-7', silent: false),
				'silent create' => $this->audited(uuid: null, silent: true),
				'silent update' => $this->audited(uuid: 'row-7', silent: true),
				'audit failure' => $this->audited(uuid: 'row-7', silent: false, auditFails: true),
			]
		);
	}
}//end class
