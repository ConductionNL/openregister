<?php

/**
 * Golden test for the column plan of a magic table.
 *
 * Records the columns and log lines MagicMapper::buildTableColumnsFromSchema()
 * yields for schemas that skip, encrypt, collide and declare linked types.
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
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicColumnNameClaims
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSchemaColumns
 * @uses   \OCA\OpenRegister\Db\ObjectHandling
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicNumberColumn
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicNumberColumnSizes
 */
class MagicMapperTableColumnsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/table-columns-golden.json';

	private array $events = [];

	private static function schemas(): array {
		$plain = ['type' => 'string'];

		return [
			'empty properties' => [[], []],
			'configuration fields' => [
				['required' => ['a'], 'objectNameField' => 'a', 'objectDescriptionField' => 'b', 'objectSummaryField' => 'c', '$schema' => ['x'], '$id' => ['y'], 'title' => $plain],
				[],
			],
			'non-array configs' => [['type' => 'object', 'count' => 3, 'kept' => $plain], []],
			'collision' => [['firstName' => $plain, 'first_name' => $plain, 'first__name' => ['type' => 'integer']], []],
			'collision with metadata' => [['_uuid' => $plain, 'name' => $plain, '_name' => $plain], []],
			'collision with linked type' => [['_mail' => $plain], ['mail']],
			'encrypted' => [
				[
					'secret' => ['type' => 'integer', 'required' => true, 'x-openregister-encrypted' => true],
					'open' => ['type' => 'integer', 'x-openregister-encrypted' => false],
					'text' => ['type' => 'string', 'x-openregister-encrypted' => 'yes'],
				],
				[],
			],
			'encrypted collision' => [['myKey' => $plain, 'my_key' => ['type' => 'object', 'x-openregister-encrypted' => true]], []],
			'linked types' => [['a' => $plain], ['files', 'mail', 'contacts', 'notes', 'todos', 'calendar', 'talk', 'deck', 'unknown']],
			'boolean true as type' => [['odd' => ['type' => true, 'format' => 'email'], 'zero' => ['type' => 0], 'list' => ['type' => ['string', 'null']]], []],
			'numeric property name' => [[7 => $plain], []],
			'numeric format' => [['odd' => ['type' => 'string', 'format' => 7]], []],
		];
	}//end schemas()

	public function testTableColumnsMatchGolden(): void {
		$mapper = $this->makeMapper();
		$actual = [];
		foreach (self::schemas() as $name => [$properties, $linkedTypes]) {
			$this->events = [];
			$schema = $this->createMock(Schema::class);
			$schema->method('getProperties')->willReturn($properties);
			$schema->method('getLinkedTypes')->willReturn($linkedTypes);
			$actual[$name] = ['columns' => $this->columnsOf($mapper, $schema), 'events' => $this->events];
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertNotEmpty($expected);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testTableColumnsMatchGolden()

	private function columnsOf(MagicMapper $mapper, Schema $schema): array|string {
		try {
			return $mapper->buildTableColumnsFromSchema(schema: $schema);
		} catch (\TypeError $error) {
			return get_class($error);
		}
	}//end columnsOf()

	private function makeMapper(): MagicMapper {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug', 'info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->events[] = ['log' => $level, 'message' => $message, 'context' => $context];
				}
			);
		}

		$reflection = new ReflectionClass(MagicMapper::class);
		$mapper = $reflection->newInstanceWithoutConstructor();
		$property = $reflection->getProperty('logger');
		$property->setAccessible(true);
		$property->setValue($mapper, $logger);

		return $mapper;
	}//end makeMapper()
}//end class
