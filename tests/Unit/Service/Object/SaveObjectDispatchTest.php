<?php

/**
 * Dispatch tests for the object-source write delegation in SaveObject.
 *
 * Covers the dbal-virtual-registers-crud contract at the dispatch layer:
 *  - a schema annotation WITHOUT `readOnly: false` keeps the v1 read-only
 *    rejection even when a writable provider is registered (opt-in default)
 *  - a non-writable provider (the eight native ones) keeps the rejection even
 *    when the annotation claims `readOnly: false`
 *  - a writable opt-in delegates create → insert() and update → update()
 *
 * The SaveObject service is instantiated without its (huge) constructor and
 * only the fields the delegation reads are injected via reflection — the
 * method under test runs its REAL logic against stub providers.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use OCA\OpenRegister\Service\ObjectSource\WritableObjectSourceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * Test class for the SaveObject object-source write dispatch.
 */
class SaveObjectDispatchTest extends TestCase {
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
			public function find(Register $register, Schema $schema, string $id, array $config = []): ?ObjectEntity {
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
			public function findAll(Register $register, Schema $schema, array $query = [], array $config = []): array {
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
			public function count(Register $register, Schema $schema, array $query = [], array $config = []): int {
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
			public function insert(Register $register, Schema $schema, array $data, array $config = []): ObjectEntity {
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
			public function update(Register $register, Schema $schema, string $id, array $data, array $config = []): ObjectEntity {
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
			public function remove(Register $register, Schema $schema, string $id, array $config = []): bool {
				$this->calls[] = 'remove';
				return true;
			}//end remove()
		};
	}//end writableProvider()

	/**
	 * Invoke the real private delegateObjectSourceWrite() with injected context.
	 *
	 * @param object $provider The provider to register.
	 * @param array<string, mixed> $objectSource The schema annotation.
	 * @param string|null $uuid The update id, or null for create.
	 *
	 * @return ObjectEntity The delegated result.
	 */
	private function delegate(object $provider, array $objectSource, ?string $uuid): ObjectEntity {
		$registry = new ObjectSourceRegistry(logger: new NullLogger());
		$registry->addProvider($provider);

		$reflection = new ReflectionClass(SaveObject::class);
		$service = $reflection->newInstanceWithoutConstructor();
		foreach ([
			'objectSourceRegistry' => $registry,
			'logger' => new NullLogger(),
			'auditTrailMapper' => $this->createMock(AuditTrailMapper::class),
		] as $property => $value
		) {
			$prop = $reflection->getProperty($property);
			$prop->setAccessible(true);
			$prop->setValue($service, $value);
		}

		$register = new Register();
		$register->setId(1);
		$schema = new Schema();
		$schema->setId(5);
		$schema->setSlug('permits');

		$method = $reflection->getMethod('delegateObjectSourceWrite');
		$method->setAccessible(true);

		return $method->invoke($service, $register, $schema, $objectSource, ['status' => 'submitted'], $uuid, true);
	}//end delegate()

	/**
	 * Without `readOnly: false` the v1 rejection stands — opt-in is the default.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testReadOnlyDefaultKeepsV1Rejection(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/read-only projection/');

		$this->delegate(
			provider: $this->writableProvider(),
			objectSource: ['provider' => 'dbal-source', 'config' => []],
			uuid: null
		);
	}//end testReadOnlyDefaultKeepsV1Rejection()

	/**
	 * A non-writable provider keeps the rejection even when the annotation
	 * claims `readOnly: false` — native providers can never be written.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testNonWritableProviderKeepsRejection(): void {
		$readOnlyProvider = new class implements \OCA\OpenRegister\Service\ObjectSource\ObjectSourceProvider {

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
			 * Unused.
			 *
			 * @param Register $register The register.
			 * @param Schema $schema The schema.
			 * @param string $id The id.
			 * @param array $config The config.
			 *
			 * @return ObjectEntity|null Always null.
			 */
			public function find(Register $register, Schema $schema, string $id, array $config = []): ?ObjectEntity {
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
			public function findAll(Register $register, Schema $schema, array $query = [], array $config = []): array {
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
			public function count(Register $register, Schema $schema, array $query = [], array $config = []): int {
				return 0;
			}//end count()
		};

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/read-only projection/');

		$this->delegate(
			provider: $readOnlyProvider,
			objectSource: ['provider' => 'dbal-source', 'readOnly' => false, 'config' => []],
			uuid: null
		);
	}//end testNonWritableProviderKeepsRejection()

	/**
	 * A writable opt-in delegates create → insert() and update → update().
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testWritableOptInDelegates(): void {
		$provider = $this->writableProvider();
		$annotation = ['provider' => 'dbal-source', 'readOnly' => false, 'config' => ['table' => 'permits']];

		$created = $this->delegate(provider: $provider, objectSource: $annotation, uuid: null);
		$this->assertSame('101', (string)$created->getUuid());
		$this->assertSame(['insert'], $provider->calls);

		$provider->calls = [];
		$updated = $this->delegate(provider: $provider, objectSource: $annotation, uuid: '7');
		$this->assertSame('7', (string)$updated->getUuid());
		$this->assertSame(['find', 'update'], $provider->calls);
	}//end testWritableOptInDelegates()
}//end class
