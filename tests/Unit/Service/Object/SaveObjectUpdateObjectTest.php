<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
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
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TmloService;
use OCA\OpenRegister\Service\TranslationProjectionService;
use OCA\OpenRegister\Service\TranslationStatusService;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * What SaveObject::updateObject() does around the write: the old object it
 * hands on, the retention recalculation, the audit trail, the file properties
 * it stores again, the image it clears and the inverse relations it starts.
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
 * @uses \OCA\OpenRegister\Db\ObjectHandling
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Service\Object\ObjectVersionHandler
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugBatch
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugClientValue
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugLog
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugSource
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugStore
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceAdminBypass
 * @uses \OCA\OpenRegister\Service\Rbac\AdminGate
 */
final class SaveObjectUpdateObjectTest extends TestCase {

	/** @var list<mixed> */
	private array $trace = [];

	/**
	 * Run one updateObject() call and return what happened, step by step.
	 *
	 * @param array $case The case: data, silent, oldObject, audit, ids, imageType.
	 *
	 * @return array
	 */
	private function runUpdate(array $case): array {
		$this->trace = [];
		$register = new Register();
		$register->setId(3);
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('docs');
		$schema->setProperties(['title' => ['type' => 'string'], 'doc' => ['type' => ($case['imageType'] ?? 'file')]]);
		$schema->setConfiguration(['objectImageField' => 'doc']);

		$existing = new ObjectEntity();
		$existing->setId(11);
		$existing->setUuid('11111111-1111-1111-1111-111111111111');
		$existing->setRegister('9');
		$existing->setSchema('7');
		$existing->setImage('img.png');
		$existing->setObject(['title' => 'old', 'kept' => 'yes']);

		$logger = new class extends AbstractLogger {
			/** @var list<string> */
			public array $lines = [];

			public function log($level, string|Stringable $message, array $context = []): void {
				$this->lines[] = $level . ': ' . $message;
			}
		};

		$handler = $this->handler(case: $case, register: $register, schema: $schema, logger: $logger);
		$old = null;
		if (($case['oldObject'] ?? false) === true) {
			$old = new ObjectEntity();
			$old->setUuid('11111111-1111-1111-1111-111111111111');
			$old->setObject(['title' => 'given old']);
		}

		$updated = $handler->updateObject(
			register: (($case['ids'] ?? false) === true) ? 3 : $register,
			schema: (($case['ids'] ?? false) === true) ? '7' : $schema,
			data: $case['data'],
			existingObject: $existing,
			folderId: null,
			silent: ($case['silent'] ?? false),
			oldObject: $old
		);

		$lines = array_values(array_filter($logger->lines, static fn (string $l): bool => str_contains($l, 'updateInverseRelations') || str_contains($l, 'archive')));
		return [
			'trace' => $this->trace,
			'register' => $updated->getRegister(),
			'version' => $updated->getVersion(),
			'schema' => $updated->getSchema(),
			'updatedSet' => $updated->getUpdated() !== null,
			'image' => $updated->getImage(),
			'lastLog' => $updated->getLastLog(),
			'object' => $updated->getObject(),
			'log' => $lines,
		];
	}

	/**
	 * The handler with tracing doubles.
	 */
	private function handler(array $case, Register $register, Schema $schema, AbstractLogger $logger): SaveObject {
		$unified = $this->createMock(MagicMapper::class);
		$unified->method('update')->willReturnCallback(
			function (ObjectEntity $entity, $register = null, $schema = null, ?ObjectEntity $oldEntity = null): ObjectEntity {
				$this->trace[] = ['update', $entity->getRegister(), $entity->getSchema(), $entity->getObject(), $oldEntity?->getObject()];
				return $entity;
			}
		);
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('update')->willReturnCallback(
			function (ObjectEntity $entity): ObjectEntity {
				$this->trace[] = ['store', $entity->getObject(), $entity->getImage()];
				return $entity;
			}
		);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(function ($id) use ($register): Register {
			$this->trace[] = ['findRegister', $id];
			return $register;
		});
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(function ($id) use ($schema): Schema {
			$this->trace[] = ['findSchema', $id];
			return $schema;
		});
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRetentionSettingsOnly')->willReturn(['auditTrailsEnabled' => ($case['audit'] ?? true)]);
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('createAuditTrail')->willReturnCallback(function (?ObjectEntity $old = null, ?ObjectEntity $new = null): AuditTrail {
			$this->trace[] = ['audit', $old?->getObject(), $new?->getObject()];
			$log = $this->createMock(AuditTrail::class);
			$log->method('jsonSerialize')->willReturn(['log' => 1]);
			return $log;
		});
		$files = $this->createMock(FilePropertyHandler::class);
		$files->method('isFileProperty')->willReturnCallback(static fn ($value, $schema = null, $propertyName = null): bool => $propertyName === 'doc');
		$files->method('handleFileProperty')->willReturnCallback(function (ObjectEntity $objectEntity, array &$object, string $propertyName): void {
			$this->trace[] = ['file', $propertyName, $object[$propertyName]];
			$object[$propertyName] = 42;
		});
		$retention = $this->createMock(RetentionService::class);
		$retention->method('recalculateArchiveActionDate')->willReturnCallback(function (ObjectEntity $object, Schema $schema, array $oldObject): ObjectEntity {
			$this->trace[] = ['retention', $oldObject];
			return $object;
		});
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => ($id === RetentionServiceInterface::class) ? $retention : throw new \RuntimeException('no ' . $id));

		return new SaveObject(
			$mapper,
			$unified,
			$this->createMock(MetadataHydrationHandler::class),
			$files,
			$this->createMock(LinkedEntityPropertyHandler::class),
			$this->createMock(IUserSession::class),
			$audit,
			$schemas,
			$registers,
			$this->createMock(IURLGenerator::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(CacheHandler::class),
			$settings,
			$this->createMock(PropertyRbacHandler::class),
			$this->createMock(ComputedFieldHandler::class),
			$this->createMock(TranslationHandler::class),
			$this->createMock(TranslationProjectionService::class),
			$this->createMock(TranslationStatusService::class),
			$logger,
			$this->createMock(TmloService::class),
			$this->createMock(FolderManagementHandler::class),
			$this->createMock(PermissionHandler::class),
			container: $container
		);
	}

	/**
	 * The cases: a file update, a silent update with a given old object, ids with audit off, and an image field that is no file.
	 *
	 * @return array<string, array>
	 */
	private static function cases(): array {
		return [
			'file update' => ['data' => ['@self' => ['name' => 'n', 'version' => '9.0.0'], 'id' => 'zzz', 'title' => 'new', 'doc' => 'raw']],
			'silent with given old' => ['data' => ['title' => 'new'], 'silent' => true, 'oldObject' => true],
			'ids and audit off' => ['data' => ['title' => 'new', 'doc' => 'raw'], 'ids' => true, 'audit' => false],
			'image field not a file' => ['data' => ['title' => 'new', 'doc' => 'raw'], 'imageType' => 'string'],
		];
	}

	public function testEveryCaseMatchesTheGoldenRecordedOnTheOldCode(): void {
		$fixture = __DIR__ . '/fixtures/save-object-update-object-golden.json';
		$actual = [];
		foreach (self::cases() as $name => $case) {
			$actual[$name] = json_decode(json_encode($this->runUpdate($case)), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents($fixture, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string)file_get_contents($fixture), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name], $name);
		}
	}
}
