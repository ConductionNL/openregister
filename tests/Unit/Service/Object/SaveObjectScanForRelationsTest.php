<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ObjectExistsException;
use OCA\OpenRegister\Search\Service\Quality\DedupCreatePolicyInterface;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Search\Service\Quality\DedupCreatePolicy;
use OCA\OpenRegister\Service\Quality\UniqueHintChecker;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TmloService;
use OCA\OpenRegister\Service\TranslationProjectionService;
use OCA\OpenRegister\Service\TranslationStatusService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * SaveObject::scanForRelations(): which values the schema makes relations,
 * which only count when they look like a reference, and the dot paths.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveLookups
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveCallFrame
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveCallStack
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceStrictness
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceEvents
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceExistence
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceFilterGuard
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceValidator
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferencePropertyRun
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceText
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\RegisterReferenceLookup
 * @uses \OCA\OpenRegister\Formats\UuidFormat
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Exception\ObjectExistsException
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugBatch
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugClientValue
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugLog
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugSource
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugStore
 * @uses \OCA\OpenRegister\Service\OrganisationContext
 */
final class SaveObjectScanForRelationsTest extends TestCase {

	private const UUID = 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4';

	/** @var MagicMapper&MockObject */
	private $objectEntityMapper;

	/** @var MagicMapper&MockObject */
	private $unifiedObjectMapper;

	/** @var PropertyRbacHandler&MockObject */
	private $propertyRbacHandler;

	/** @var array<string, object> */
	private array $services = [];

	private Register $register;

	private Schema $schema;

	protected function setUp(): void {
		parent::setUp();
		$this->objectEntityMapper = $this->createMock(MagicMapper::class);
		$this->unifiedObjectMapper = $this->createMock(MagicMapper::class);
		$this->propertyRbacHandler = $this->createMock(PropertyRbacHandler::class);

		$this->register = new Register();
		$this->register->setId(3);
		$this->schema = new Schema();
		$this->schema->setId(7);
		$this->schema->setSlug('things');
		$this->schema->setProperties(['name' => ['type' => 'string']]);
	}

	private function saveObject(): SaveObject {
		$translations = $this->createMock(TranslationHandler::class);
		$translations->method('normalizeTranslationsForSave')->willReturnCallback(
			static fn (array $objectData): array => $objectData
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id): object {
			if (isset($this->services[$id]) === false) {
				throw new RuntimeException('no ' . $id);
			}

			return $this->services[$id];
		});

		return new SaveObject(
			$this->objectEntityMapper,
			$this->unifiedObjectMapper,
			$this->createMock(MetadataHydrationHandler::class),
			$this->createMock(FilePropertyHandler::class),
			$this->createMock(LinkedEntityPropertyHandler::class),
			$this->createMock(IUserSession::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(CacheHandler::class),
			$this->createMock(SettingsService::class),
			$this->propertyRbacHandler,
			$this->createMock(ComputedFieldHandler::class),
			$translations,
			$this->createMock(TranslationProjectionService::class),
			$this->createMock(TranslationStatusService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(TmloService::class),
			$this->createMock(FolderManagementHandler::class),
			$this->createMock(PermissionHandler::class),
			container: $container
		);
	}

	private function stored(): ObjectEntity {
		$stored = new ObjectEntity();
		$stored->setId(11);
		$stored->setUuid(self::UUID);
		$stored->setRegister('3');
		$stored->setSchema('7');
		$stored->setObject(['name' => 'Stored']);
		return $stored;
	}

	/**
	 * A dedup policy whose guard records what it was asked and then stops the save.
	 *
	 * @param array $seen Receives the data and the override flag.
	 */
	private function stoppingDedupPolicy(array &$seen): void {
		$policy = $this->createMock(DedupCreatePolicy::class);
		$policy->method('guardCreate')->willReturnCallback(
			static function ($register, $schema, array $data, bool $overrideRequested) use (&$seen): array {
				$seen = ['register' => $register, 'schema' => $schema, 'data' => $data, 'override' => $overrideRequested];
				throw new RuntimeException('stop');
			}
		);
		$this->services[DedupCreatePolicyInterface::class] = $policy;
	}

	public function testTheSchemaDecidesWhichStringsAreRelations(): void {
		$this->schema->setProperties([
			'tags' => ['type' => 'array', 'items' => ['type' => 'object']],
			'ref' => ['type' => 'text', 'format' => 'uuid'],
			'link' => ['type' => 'text', 'format' => 'url'],
			'mail' => ['type' => 'text', 'format' => 'email'],
			'owner' => ['type' => 'object'],
			'plain' => ['type' => 'string'],
		]);
		$uuid = '55555555-5555-5555-5555-555555555555';
		$data = [
			'tags' => ['hello', '', '0', ['nested' => 'not-a-ref', 'deep' => $uuid]],
			'ref' => 'free text',
			'link' => 'free text',
			'mail' => 'free text',
			'owner' => 'bob',
			'plain' => 'hello',
			'list' => ['hello', $uuid, '', ' '],
			'single' => $uuid,
			'' => $uuid,
		];

		$this->assertSame(
			[
				'root.tags.0' => 'hello',
				'root.tags.3.nested' => 'not-a-ref',
				'root.tags.3.deep' => $uuid,
				'root.ref' => 'free text',
				'root.link' => 'free text',
				'root.owner' => 'bob',
				'root.list.1' => $uuid,
				'root.single' => $uuid,
			],
			$this->saveObject()->scanForRelations($data, 'root', $this->schema)
		);
	}

	public function testWithoutASchemaOnlyReferencesCount(): void {
		$uuid = '55555555-5555-5555-5555-555555555555';
		$this->assertSame(
			['tags.1' => $uuid, 'single' => $uuid],
			$this->saveObject()->scanForRelations(['owner' => 'bob', 'tags' => ['x', $uuid], 'single' => $uuid])
		);
	}
}
