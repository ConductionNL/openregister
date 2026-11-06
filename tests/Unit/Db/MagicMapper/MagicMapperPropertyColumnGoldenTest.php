<?php

/**
 * Golden test for MagicSchemaColumns::forProperty().
 *
 * Records the column definition and the log lines for every property type,
 * format, required form and reference shape, so a decomposition keeps the
 * exact output. Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicSchemaColumns;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSchemaColumns
 * @uses   \OCA\OpenRegister\Db\ObjectHandling
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicNumberColumn
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicNumberColumnSizes
 */
class MagicMapperPropertyColumnGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/property-column-golden.json';

	private array $events = [];

	private static function properties(): array {
		$required = [
			'not set' => [],
			'true' => ['required' => true],
			'false' => ['required' => false],
			'list with name' => ['required' => ['myProp', 'other']],
			'list with column' => ['required' => ['my_prop']],
			'list without' => ['required' => ['other']],
			'string' => ['required' => 'yes'],
			'list loose' => ['required' => [0]],
			'list loose true' => ['required' => [true]],
		];
		$shapes = [
			'string' => ['type' => 'string'],
			'string maxLength' => ['type' => 'string', 'maxLength' => 40],
			'string long' => ['type' => 'string', 'maxLength' => 5000],
			'date' => ['type' => 'string', 'format' => 'date'],
			'date-time' => ['type' => 'string', 'format' => 'date-time'],
			'uuid' => ['type' => 'string', 'format' => 'uuid'],
			'email' => ['type' => 'string', 'format' => 'email'],
			'uri' => ['type' => 'string', 'format' => 'uri'],
			'integer' => ['type' => 'integer'],
			'integer bounded' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10],
			'integer big' => ['type' => 'integer', 'maximum' => 99999999999],
			'integer maximum only' => ['type' => 'integer', 'maximum' => 10],
			'integer mid range' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000],
			'integer negative minimum' => ['type' => 'integer', 'minimum' => -1, 'maximum' => 10],
			'integer smallint bound' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 65535],
			'integer integer bound' => ['type' => 'integer', 'maximum' => 2147483647],
			'number' => ['type' => 'number'],
			'number money' => ['type' => 'number', 'multipleOf' => 0.01],
			'boolean' => ['type' => 'boolean'],
			'boolean default' => ['type' => 'boolean', 'default' => true],
			'boolean null default' => ['type' => 'boolean', 'default' => null],
			'file' => ['type' => 'file'],
			'array' => ['type' => 'array'],
			'array of refs' => ['type' => 'array', 'items' => ['$ref' => '#/x']],
			'object' => ['type' => 'object'],
			'object ref related' => ['type' => 'object', '$ref' => '#/a', 'objectConfiguration' => ['handling' => 'related-object']],
			'object ref schema' => ['type' => 'object', '$ref' => '#/a', 'objectConfiguration' => ['handling' => 'related-schema']],
			'object ref nested' => ['type' => 'object', '$ref' => '#/a', 'objectConfiguration' => ['handling' => 'nested-object']],
			'object related no ref' => ['type' => 'object', 'objectConfiguration' => ['handling' => 'related-object']],
			'object oneOf related' => ['type' => 'object', 'items' => ['oneOf' => [
				['$ref' => '#/p'],
				['$ref' => '#/q', 'objectConfiguration' => ['handling' => 'related-object']],
			]]],
			'object oneOf no ref' => ['type' => 'object', 'items' => ['oneOf' => [['objectConfiguration' => ['handling' => 'related-schema']]]]],
			'object oneOf nested then related' => ['type' => 'object', 'items' => ['oneOf' => [
				['$ref' => '#/p', 'objectConfiguration' => ['handling' => 'nested-object']],
				['$ref' => '#/q', 'objectConfiguration' => ['handling' => 'related-schema']],
			]]],
			'object oneOf nested' => ['type' => 'object', 'items' => ['oneOf' => [['$ref' => '#/q', 'objectConfiguration' => ['handling' => 'nested-object']]]]],
			'object oneOf with handling' => ['type' => 'object', '$ref' => '#/z', 'objectConfiguration' => ['handling' => 'nested-object'], 'items' => ['oneOf' => [['$ref' => '#/q', 'objectConfiguration' => ['handling' => 'related-object']]]]],
			'array oneOf related' => ['type' => 'array', 'items' => ['oneOf' => [['$ref' => '#/q', 'objectConfiguration' => ['handling' => 'related-object']]]]],
			'unknown' => ['type' => 'geometry'],
			'untyped' => [],
		];
		$out = [];
		foreach ($shapes as $shape => $config) {
			foreach ($required as $form => $extra) {
				$out[$shape . ' / required ' . $form] = $config + $extra;
			}
		}

		return $out;
	}//end properties()

	public function testPropertyColumnsMatchGolden(): void {
		$columns = new MagicSchemaColumns(logger: $this->makeLogger());
		$actual = [];
		foreach (self::properties() as $name => $config) {
			$this->events = [];
			foreach (['myProp', 'Weird Name!'] as $propertyName) {
				$this->events[] = ['column' => $columns->forProperty(propertyName: $propertyName, propertyConfig: $config)];
			}

			$actual[$name] = $this->events;
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $events) {
			$this->assertSame($events, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testPropertyColumnsMatchGolden()

	private function makeLogger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug', 'info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->events[] = ['log' => $level, 'message' => $message, 'context' => $context];
				}
			);
		}

		return $logger;
	}//end makeLogger()
}//end class
