<?php

/**
 * RenderObject field-level-object-encryption integration tests.
 *
 * Exercises the real `renderEntity()` and `redactWriteOnlyFromRows()` methods
 * to prove:
 *  - A flagged property is decrypted for an authorized (redaction-surviving) read.
 *  - A flagged property that ALSO fails property-authorization is never
 *    decrypted — decryptProperties() only acts on keys still present after the
 *    redaction block runs first, so an unauthorized caller never sees
 *    ciphertext or plaintext, only the same absence every other redacted
 *    property gets.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/field-level-object-encryption/specs/field-level-encryption/spec.md#requirement-authorized-reads-are-decrypted-unauthorized-reads-never-see-ciphertext
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FieldEncryptionHandler;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\Security\ICrypto;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class RenderObjectFieldEncryptionTest extends TestCase {
	private RenderObject $handler;

	private FileMapper&MockObject $fileMapper;

	private MagicMapper&MockObject $objectMapper;

	private RegisterMapper&MockObject $registerMapper;

	private SchemaMapper&MockObject $schemaMapper;

	private PropertyRbacHandler&MockObject $propertyRbacHandler;

	private FieldEncryptionHandler $fieldEncryptionHandler;

	/** @var ICrypto&MockObject */
	private $crypto;

	protected function setUp(): void {
		parent::setUp();

		$this->fileMapper = $this->createMock(FileMapper::class);
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->propertyRbacHandler = $this->createMock(PropertyRbacHandler::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->fileMapper->method('getFilesForObject')->willReturn([]);

		$translationHandler = $this->createMock(\OCA\OpenRegister\Service\Object\TranslationHandler::class);
		$translationHandler->method('resolveTranslationsForRender')
			->willReturnCallback(function (array $objectData) {
				return $objectData;
			});

		$this->crypto = $this->createMock(ICrypto::class);
		$this->crypto->method('encrypt')->willReturnCallback(
			fn (string $plain): string => 'CIPHER(' . $plain . ')'
		);
		$this->crypto->method('decrypt')->willReturnCallback(
			function (string $cipher): string {
				if (preg_match('/^CIPHER\((.*)\)$/', $cipher, $m) === 1) {
					return $m[1];
				}

				throw new \RuntimeException('bad ciphertext');
			}
		);
		$this->fieldEncryptionHandler = new FieldEncryptionHandler($this->crypto, $logger);

		$this->handler = new RenderObject(
			stores: new \OCA\OpenRegister\Service\Object\RenderStores(
				mappers: new \OCA\OpenRegister\Service\Object\RenderMappers(
					catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->registerMapper, schemas: $this->schemaMapper, tables: $this->objectMapper),
					objectCacheService: $this->createMock(CacheHandler::class)
				),
				files: new \OCA\OpenRegister\Service\Object\RenderFileSources(
					fileMapper: $this->fileMapper,
					fileService: $this->createMock(FileService::class),
					systemTagManager: $this->createMock(ISystemTagManager::class),
					systemTagMapper: $this->createMock(ISystemTagObjectMapper::class)
				)
			),
			enrichers: new \OCA\OpenRegister\Service\Object\RenderEnrichers(
				translations: new \OCA\OpenRegister\Service\Object\RenderTranslations(
					translationHandler: $translationHandler,
					translationStatusService: $this->createMock(\OCA\OpenRegister\Service\TranslationStatusService::class),
					translationMapper: $this->createMock(\OCA\OpenRegister\Db\TranslationMapper::class),
					languageService: $this->createMock(\OCA\OpenRegister\Service\LanguageService::class)
				),
				derivations: new \OCA\OpenRegister\Service\Object\RenderDerivations(
					computedFieldHandler: $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler::class),
					calculationEvaluator: $this->createMock(\OCA\OpenRegister\Service\Calculation\CalculationEvaluator::class),
					linkedEntityEnricher: $this->createMock(\OCA\OpenRegister\Service\Object\LinkedEntityEnricher::class),
					urnService: $this->createMock(\OCA\OpenRegister\Service\UrnService::class)
				)
			),
			runtime: new \OCA\OpenRegister\Service\Object\RenderRuntime(
				logger: $logger,
				extensions: new \OCA\OpenRegister\Service\Object\RenderReadExtensions(objectSourceRegistry: null, fieldEncryptionHandler: $this->fieldEncryptionHandler),
				request: null
			),
			propertyRbacHandler: $this->propertyRbacHandler
		);
	}

	private function createSchema(int $id, array $properties): Schema {
		$schema = new Schema();
		$ref = new ReflectionClass($schema);
		$idProp = $ref->getProperty('id');
		$idProp->setAccessible(true);
		$idProp->setValue($schema, $id);
		$schema->setSlug('test-schema');
		$schema->setTitle('Test Schema');
		$schema->setProperties($properties);
		return $schema;
	}

	private function createEntity(array $objectData): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setId(1);
		$entity->setUuid('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
		$entity->setSchema(1);
		$entity->setRegister(1);
		$objectData['id'] = $entity->getUuid();
		$entity->setObject($objectData);
		return $entity;
	}

	public function testAuthorizedReadDecryptsFlaggedProperty(): void {
		$schema = $this->createSchema(1, [
			'name' => ['type' => 'string'],
			'bsn' => ['type' => 'string', 'x-openregister-encrypted' => true],
		]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$envelope = $this->fieldEncryptionHandler->encryptValue('123456789');
		$entity = $this->createEntity(['name' => 'Jan Jansen', 'bsn' => $envelope]);

		$result = $this->handler->renderEntity($entity);
		$serialized = $result->jsonSerialize();

		$this->assertSame('123456789', $serialized['bsn'], 'Authorized read must return decrypted plaintext');
		$this->assertSame('Jan Jansen', $serialized['name']);
	}

	public function testCiphertextIsNeverReturnedVerbatim(): void {
		$schema = $this->createSchema(1, [
			'bsn' => ['type' => 'string', 'x-openregister-encrypted' => true],
		]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$envelope = $this->fieldEncryptionHandler->encryptValue('123456789');
		$entity = $this->createEntity(['bsn' => $envelope]);

		$result = $this->handler->renderEntity($entity);
		$serialized = $result->jsonSerialize();

		$this->assertNotSame($envelope, $serialized['bsn'], 'Raw ciphertext must never reach the caller');
		$this->assertStringNotContainsString(FieldEncryptionHandler::ENVELOPE_PREFIX, (string)$serialized['bsn']);
	}

	public function testUnauthorizedReadNeverSeesCiphertextOrPlaintext(): void {
		// Property-level authorization denies 'bsn' entirely: filterReadableProperties
		// strips it BEFORE decryptProperties() runs, so decryption never happens for it.
		$schema = $this->createSchema(1, [
			'name' => ['type' => 'string', 'authorization' => ['read' => [['group' => 'admin']]]],
			'bsn' => [
				'type' => 'string',
				'x-openregister-encrypted' => true,
				'authorization' => ['read' => [['group' => 'admin']]],
			],
		]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->propertyRbacHandler->method('filterReadableProperties')
			->willReturnCallback(function ($s, array $obj) {
				unset($obj['bsn'], $obj['name']);
				return $obj;
			});

		$envelope = $this->fieldEncryptionHandler->encryptValue('123456789');
		$entity = $this->createEntity(['name' => 'Jan Jansen', 'bsn' => $envelope]);

		$result = $this->handler->renderEntity($entity);
		$serialized = $result->jsonSerialize();

		$this->assertArrayNotHasKey('bsn', $serialized, 'Unauthorized caller must get redaction, not ciphertext or plaintext');
	}

	public function testDecryptionFailureSurfacesStructuredErrorNotSilentLoss(): void {
		$schema = $this->createSchema(1, [
			'bsn' => ['type' => 'string', 'x-openregister-encrypted' => true],
		]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$corrupted = FieldEncryptionHandler::ENVELOPE_PREFIX . 'corrupted-ciphertext';
		$entity = $this->createEntity(['bsn' => $corrupted]);

		$result = $this->handler->renderEntity($entity);
		$serialized = $result->jsonSerialize();

		$this->assertIsArray($serialized['bsn']);
		$this->assertTrue($serialized['bsn']['@openregister_decryption_error']);
	}

	public function testRedactWriteOnlyFromRowsDecryptsArrayRows(): void {
		$schema = $this->createSchema(2, [
			'bsn' => ['type' => 'string', 'x-openregister-encrypted' => true],
		]);
		$this->schemaMapper->method('find')->willReturn($schema);

		// Redaction is a no-op for this schema (no writeOnly / property-authz); make
		// the mock pass the row through unchanged so we isolate the decryption step.
		$this->propertyRbacHandler->method('filterReadableProperties')
			->willReturnCallback(fn ($s, array $o) => $o);
		$this->propertyRbacHandler->method('stripWriteOnlyProperties')
			->willReturnCallback(fn ($s, array $o) => $o);

		$envelope = $this->fieldEncryptionHandler->encryptValue('987654321');
		$rows = [
			[
				'@self' => ['schema' => 2],
				'bsn' => $envelope,
			],
		];

		$this->handler->redactWriteOnlyFromRows($rows, true);

		$this->assertSame('987654321', $rows[0]['bsn']);
	}

	public function testRedactWriteOnlyFromRowsDecryptsObjectEntityRows(): void {
		$schema = $this->createSchema(3, [
			'bsn' => ['type' => 'string', 'x-openregister-encrypted' => true],
		]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->propertyRbacHandler->method('filterReadableProperties')
			->willReturnCallback(fn ($s, array $o) => $o);
		$this->propertyRbacHandler->method('stripWriteOnlyProperties')
			->willReturnCallback(fn ($s, array $o) => $o);

		$envelope = $this->fieldEncryptionHandler->encryptValue('555555555');
		$entity = new ObjectEntity();
		$entity->setSchema(3);
		$entity->setObject(['bsn' => $envelope]);

		$rows = [$entity];
		$this->handler->redactWriteOnlyFromRows($rows, true);

		$this->assertSame('555555555', $rows[0]->getObject()['bsn']);
	}

	public function testRedactWriteOnlyFromRowsBypassedForSystemContext(): void {
		$schema = $this->createSchema(4, [
			'bsn' => ['type' => 'string', 'x-openregister-encrypted' => true],
		]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$envelope = $this->fieldEncryptionHandler->encryptValue('111111111');
		$rows = [
			[
				'@self' => ['schema' => 4],
				'bsn' => $envelope,
			],
		];

		// _rbac: false = trusted internal read; must NOT decrypt (ciphertext stays
		// ciphertext for internal code that reads the raw row directly).
		$this->handler->redactWriteOnlyFromRows($rows, false);

		$this->assertSame($envelope, $rows[0]['bsn']);
	}
}
