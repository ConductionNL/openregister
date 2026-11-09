<?php

/**
 * Raw SQL must name the table the connection and MagicTableCreator name.
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

use OCA\OpenRegister\Db\MagicMapper;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper
 */
class MagicMapperEmptyTablePrefixTest extends TestCase {

	public function testAnEmptyPrefixStaysEmpty(): void {
		$this->assertSame('openregister_table_1_2', $this->fullTableName(''));
	}//end testAnEmptyPrefixStaysEmpty()

	public function testACustomPrefixIsUsed(): void {
		$this->assertSame('nc_openregister_table_1_2', $this->fullTableName('nc_'));
	}//end testACustomPrefixIsUsed()

	private function fullTableName(string $prefix): string {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->with('dbtableprefix', 'oc_')->willReturn($prefix);

		$reflection = new ReflectionClass(MagicMapper::class);
		$mapper = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('config')->setValue($mapper, $config);

		return $reflection->getMethod('getFullTableName')->invoke($mapper, 'openregister_table_1_2');
	}//end fullTableName()
}//end class
