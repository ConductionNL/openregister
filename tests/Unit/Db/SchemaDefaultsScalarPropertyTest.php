<?php

/**
 * A schema property that is no object does not stop the required list.
 *
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);


namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDefaults;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\SchemaMapper\SchemaDefaults
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class SchemaDefaultsScalarPropertyTest extends TestCase {
	/**
	 * Every other save step skips such a property; this one raised a TypeError, a 500.
	 *
	 * @return void
	 */
	public function testAScalarPropertyIsNotRequiredAndLeavesTheOthersAlone(): void {
		$schema = new Schema();
		$schema->setProperties(['a' => 'nonsense', 'b' => ['required' => true]]);

		(new SchemaDefaults())->buildRequired(schema: $schema);

		$this->assertSame(['b'], $schema->getRequired());
	}//end testAScalarPropertyIsNotRequiredAndLeavesTheOthersAlone()
}//end class
