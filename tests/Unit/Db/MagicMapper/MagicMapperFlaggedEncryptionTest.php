<?php

/**
 * An encrypted property reaches a magic table only as an envelope, on both write paths.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/field-level-encryption/spec.md#requirement-flagged-properties-are-encrypted-on-save
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\FieldEncryptionHandler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use stdClass;

/**
 * Runs through MagicMapper, so the cases hold wherever the encryption step lives.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicFlaggedEncryption
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicDroppedProperties
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumn
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPropertyColumns
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPropertyDate
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicRowMetadata
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicUnprocessedUploads
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class MagicMapperFlaggedEncryptionTest extends TestCase {
	private const SECRET = 'plain-bsn';

	private const ENVELOPE = 'openregister:enc:v1:x';

	/**
	 * @var array<int|string, mixed>
	 */
	private array $handedToBulk = [];

	private function schema(bool $encrypted, ?string $slug = 'people'): Schema {
		$schema = $this->getMockBuilder(Schema::class)->onlyMethods(['getLinkedTypes', 'hasEncryptedProperties'])->getMock();
		$schema->method('getLinkedTypes')->willReturn([]);
		$schema->method('hasEncryptedProperties')->willReturn($encrypted);
		$schema->setId(5);
		$schema->setSlug($slug);
		$schema->setProperties(['bsn' => ['type' => 'string', 'x-openregister-encrypted' => $encrypted]]);

		return $schema;
	}//end schema()

	private function mapper(mixed $handler): MagicMapper {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($handler);
		$bulk = $this->createMock(MagicBulkHandler::class);
		$bulk->method('bulkUpsert')->willReturnCallback(
			function (array $objects): array {
				$this->handedToBulk = $objects;
				return [];
			}
		);
		$reflection = new ReflectionClass(MagicMapper::class);
		$mapper = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('container')->setValue($mapper, $container);
		$reflection->getProperty('logger')->setValue($mapper, $this->createMock(LoggerInterface::class));
		$reflection->getProperty('bulkHandler')->setValue($mapper, $bulk);

		return $mapper;
	}//end mapper()

	private function handler(): FieldEncryptionHandler {
		$handler = $this->createMock(FieldEncryptionHandler::class);
		$handler->method('encryptProperties')->willReturnCallback(
			static fn (array $data): array => array_replace($data, array_intersect_key(['bsn' => self::ENVELOPE], $data))
		);

		return $handler;
	}//end handler()

	private function prepare(MagicMapper $mapper, Schema $schema): array {
		$register = new Register();
		$register->setId(3);
		$method = new ReflectionMethod(MagicMapper::class, 'prepareObjectDataForTable');

		return $method->invoke($mapper, ['bsn' => self::SECRET], new \OCA\OpenRegister\Db\RegisterSchema($register, $schema));
	}//end prepare()

	private function bulk(MagicMapper $mapper, array $rows, Schema $schema): void {
		$mapper->bulkUpsert($rows, new Register(), $schema, 't');
	}//end bulk()

	public function testASingleWriteStoresTheEnvelope(): void {
		$this->assertSame(self::ENVELOPE, $this->prepare($this->mapper($this->handler()), $this->schema(true))['bsn']);
	}//end testASingleWriteStoresTheEnvelope()

	public function testASchemaWithoutEncryptionNeverAsksForTheHandler(): void {
		$prepared = $this->prepare($this->mapper(new stdClass()), $this->schema(false));

		$this->assertSame(self::SECRET, $prepared['bsn']);
	}//end testASchemaWithoutEncryptionNeverAsksForTheHandler()

	public function testAMissingHandlerRefusesTheWriteNamingTheSlug(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Schema "people" has encrypted properties but no FieldEncryptionHandler is available');

		$this->prepare($this->mapper(new stdClass()), $this->schema(true));
	}//end testAMissingHandlerRefusesTheWriteNamingTheSlug()

	public function testAMissingHandlerNamesTheIdWithoutASlug(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Schema "5" has encrypted properties');

		$this->prepare($this->mapper(new stdClass()), $this->schema(true, null));
	}//end testAMissingHandlerNamesTheIdWithoutASlug()

	public function testABulkWriteEncryptsBothRowShapes(): void {
		$rows = ['nested' => ['object' => ['bsn' => self::SECRET]], 'flat' => ['bsn' => self::SECRET, '@self' => ['id' => 'u']]];

		$this->bulk($this->mapper($this->handler()), $rows, $this->schema(true));

		$this->assertSame(self::ENVELOPE, $this->handedToBulk['nested']['object']['bsn']);
		$this->assertSame(self::ENVELOPE, $this->handedToBulk['flat']['bsn']);
		$this->assertSame(['id' => 'u'], $this->handedToBulk['flat']['@self']);
	}//end testABulkWriteEncryptsBothRowShapes()

	public function testABulkRowWhoseObjectIsNoArrayIsEncryptedAsAFlatRow(): void {
		$this->bulk($this->mapper($this->handler()), [['object' => 'text', 'bsn' => self::SECRET]], $this->schema(true));

		$this->assertSame(['object' => 'text', 'bsn' => self::ENVELOPE], $this->handedToBulk[0]);
	}//end testABulkRowWhoseObjectIsNoArrayIsEncryptedAsAFlatRow()

	public function testABulkEntryThatIsNoRowIsPassedOn(): void {
		$this->bulk($this->mapper($this->handler()), ['scalar', ['bsn' => self::SECRET]], $this->schema(true));

		$this->assertSame('scalar', $this->handedToBulk[0]);
		$this->assertSame(self::ENVELOPE, $this->handedToBulk[1]['bsn']);
	}//end testABulkEntryThatIsNoRowIsPassedOn()

	public function testABulkWriteWithoutEncryptionLeavesTheRows(): void {
		$rows = [['bsn' => self::SECRET]];

		$this->bulk($this->mapper(new stdClass()), $rows, $this->schema(false));

		$this->assertSame($rows, $this->handedToBulk);
	}//end testABulkWriteWithoutEncryptionLeavesTheRows()

	public function testABulkWriteWithoutHandlerIsRefused(): void {
		$this->expectException(RuntimeException::class);

		$this->bulk($this->mapper(new stdClass()), [['bsn' => self::SECRET]], $this->schema(true));
	}//end testABulkWriteWithoutHandlerIsRefused()
}//end class
