<?php

/**
 * Golden test for the register and schema chart data of MagicStatisticsHandler.
 *
 * Records getRegisterChartData() and getSchemaChartData() over register and
 * schema filters, untitled registers and schemas, a register that cannot be
 * found and counts that add up per title, together with every lookup and
 * count the handler makes. Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCP\IConfig;
use OCA\OpenRegister\Db\MagicMapper\MagicTableSql;
use OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumn
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RegisterSchemaFilter
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class MagicStatisticsChartDataGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/magic-statistics-chart-data-golden.json';

	/**
	 * @var array<int, string>
	 */
	private array $log = [];

	private const PAIRS = [
		['registerId' => 1, 'schemaId' => 10],
		['registerId' => 1, 'schemaId' => 11],
		['registerId' => '2', 'schemaId' => '10'],
		['registerId' => 3, 'schemaId' => 12],
		['registerId' => 4, 'schemaId' => 13],
		['registerId' => 9, 'schemaId' => 10],
	];

	public function testChartDataMatchesGolden(): void {
		$actual = [];
		foreach (['getRegisterChartData', 'getSchemaChartData'] as $method) {
			foreach ([[null, null], [1, null], [null, 10], [1, 11], [7, null], [9, null]] as [$registerId, $schemaId]) {
				$name = $method . ' / ' . var_export($registerId, true) . ' / ' . var_export($schemaId, true);
				$this->log = [];
				$result = $this->handler()->$method(registerId: $registerId, schemaId: $schemaId);
				$actual[$name] = ['result' => $result, 'calls' => $this->log];
			}
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testChartDataMatchesGolden()

	private function handler(): MagicStatisticsHandler {
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturnCallback(function (mixed ...$args): Register {
			$id = $args[0];
			$this->log[] = 'register ' . json_encode($args);
			if ((int)$id === 9) {
				throw new DoesNotExistException('gone');
			}

			$register = new Register();
			$register->setId((int)$id);
			$register->setTitle((int)$id === 3 ? null : 'Register title ' . ((int)$id === 2 ? 1 : $id));
			return $register;
		});
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(function (mixed ...$args): Schema {
			$id = $args[0];
			$this->log[] = 'schema ' . json_encode($args);
			$schema = new Schema();
			$schema->setId((int)$id);
			$schema->setTitle((int)$id === 13 ? null : 'Schema title ' . $id);
			return $schema;
		});

		$handler = $this->getMockBuilder(MagicStatisticsHandler::class)
			->setConstructorArgs([
				new MagicTableSql($this->createMock(IDBConnection::class), $this->createMock(IConfig::class), new NullLogger()),
				new SchemaCatalog($registers, $schemas, $this->createMock(MagicMapperInterface::class)),
				$this->createMock(DateTimeNormalizer::class),
				$this->createMock(SchemaTypeConverter::class),
			])
			->onlyMethods(['getAllRegisterSchemaPairs'])
			->getMock();
		$handler->method('getAllRegisterSchemaPairs')->willReturn(self::PAIRS);
		$handler->setCountCallback(function (array $query, Register $register, Schema $schema): int {
			$this->log[] = 'count ' . $register->getId() . '/' . $schema->getId() . ' ' . json_encode($query);
			return $register->getId() * 100 + $schema->getId();
		});

		return $handler;
	}//end handler()
}//end class
