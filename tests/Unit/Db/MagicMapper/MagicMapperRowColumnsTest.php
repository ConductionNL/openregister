<?php

/**
 * Warnings and edge values of the columns written for one object.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicRowMetadata;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use ReflectionClass;
use ReflectionMethod;
use Stringable;

/**
 * Runs through MagicMapper's private row preparation.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicDroppedProperties
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicPropertyColumns
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicPropertyDate
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicUnprocessedUploads
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicFlaggedEncryption
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicRowMetadata
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumn
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class MagicMapperRowColumnsTest extends TestCase {
	private AbstractLogger $logger;

	private MagicMapper $mapper;

	protected function setUp(): void {
		$this->logger = new class extends AbstractLogger {
			/**
			 * @var list<string>
			 */
			public array $warnings = [];

			public function log($level, string|Stringable $message, array $context=[]): void {
				$this->warnings[] = (string) $message;
			}//end log()
		};
		$reflection = new ReflectionClass(MagicMapper::class);
		$this->mapper = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('logger')->setValue($this->mapper, $this->logger);
		$reflection->getProperty('container')->setValue($this->mapper, $this->createMock(ContainerInterface::class));
	}//end setUp()

	private function prepare(array $data, Schema $schema): array {
		$register = new Register();
		$register->setId(3);

		return (new ReflectionMethod(MagicMapper::class, 'prepareObjectDataForTable'))->invoke($this->mapper, $data, $register, $schema);
	}//end prepare()

	private function schema(int $id, array $properties): Schema {
		$schema = $this->getMockBuilder(Schema::class)->onlyMethods(['getLinkedTypes'])->getMock();
		$schema->method('getLinkedTypes')->willReturn([]);
		$schema->setId($id);
		$schema->setProperties($properties);

		return $schema;
	}//end schema()

	private function warningsAbout(string $text): int {
		return count(array_filter($this->logger->warnings, static fn (string $line): bool => str_contains($line, $text)));
	}//end warningsAbout()

	public function testAnUndeclaredPropertyIsReportedOncePerSchema(): void {
		$schema = $this->schema(9101, ['title' => ['type' => 'string']]);

		$this->prepare(['extra' => 1], $schema);
		$this->prepare(['extra' => 2], $schema);
		$this->prepare(['extra' => 3, 'other' => 4], $schema);

		$this->assertSame(2, $this->warningsAbout('Discarding 1 property'));
		$this->assertSame(1, $this->warningsAbout('does not declare: other.'));
	}//end testAnUndeclaredPropertyIsReportedOncePerSchema()

	public function testTheSameUndeclaredPropertyIsReportedForAnotherSchema(): void {
		$this->prepare(['extra' => 1], $this->schema(9102, []));
		$this->prepare(['extra' => 1], $this->schema(9103, []));

		$this->assertSame(2, $this->warningsAbout('does not declare: extra.'));
	}//end testTheSameUndeclaredPropertyIsReportedForAnotherSchema()

	public function testAMetadataNamedPropertyWarnsOnce(): void {
		$schema = $this->schema(9104, ['_owner' => ['type' => 'string']]);

		$this->prepare(['_owner' => 'mallory'], $schema);
		$prepared = $this->prepare(['_owner' => 'mallory'], $schema);

		$this->assertSame(1, $this->warningsAbout('named like a metadata column'));
		$this->assertNotSame('mallory', $prepared['_owner']);
	}//end testAMetadataNamedPropertyWarnsOnce()

	public function testAFileValueThatOnlyContainsDataIsKept(): void {
		$prepared = $this->prepare(['file' => 'see data:here'], $this->schema(9105, ['file' => ['type' => 'file']]));

		$this->assertSame('see data:here', $prepared['file']);
	}//end testAFileValueThatOnlyContainsDataIsKept()

	public function testItemsOfAPropertyThatIsNoArrayOfFilesAreKept(): void {
		$schema = $this->schema(9106, ['meta' => ['type' => 'object', 'items' => ['type' => 'file']]]);

		$prepared = $this->prepare(['meta' => ['data:x']], $schema);

		$this->assertSame('["data:x"]', $prepared['meta']);
	}//end testItemsOfAPropertyThatIsNoArrayOfFilesAreKept()

	public function testANullDateStaysNull(): void {
		$prepared = $this->prepare(['due' => null], $this->schema(9107, ['due' => ['type' => 'string', 'format' => 'date']]));

		$this->assertNull($prepared['due']);
	}//end testANullDateStaysNull()

	public function testAPropertyWithoutADefinitionIsStillWritten(): void {
		$prepared = $this->prepare(['bare' => 'x'], $this->schema(9108, ['bare' => null]));

		$this->assertSame('x', $prepared['bare']);
	}//end testAPropertyWithoutADefinitionIsStillWritten()

	public function testClientAuthorizationRulesAreNotCarried(): void {
		$rowMetadata = new MagicRowMetadata($this->createMock(ContainerInterface::class));

		$metadata = $rowMetadata->authoritative(['@self' => ['authorization' => ['read' => ['x']]]], ['register' => 3, 'schema' => 4]);

		$this->assertArrayNotHasKey('authorization', $metadata);
	}//end testClientAuthorizationRulesAreNotCarried()
}//end class
