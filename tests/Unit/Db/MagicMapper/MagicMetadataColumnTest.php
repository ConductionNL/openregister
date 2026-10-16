<?php

/**
 * Which underscore-prefixed magic-table columns are metadata.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumn;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumn
 */
class MagicMetadataColumnTest extends TestCase {

	/**
	 * @return void
	 */
	public function testAColumnWithoutThePrefixIsAProperty(): void {
		$this->assertNull((new MagicMetadataColumn())->field(column: 'code', columnToPropertyMap: []));
	}//end testAColumnWithoutThePrefixIsAProperty()

	/**
	 * @return void
	 */
	public function testAPrefixedColumnTheSchemaMapsToAPropertyIsThatProperty(): void {
		$this->assertNull((new MagicMetadataColumn())->field(column: '_meta', columnToPropertyMap: ['_meta' => '_meta']));
	}//end testAPrefixedColumnTheSchemaMapsToAPropertyIsThatProperty()

	/**
	 * Control: the same column without a property in the schema is metadata.
	 *
	 * @return void
	 */
	public function testAPrefixedColumnWithoutAPropertyIsMetadata(): void {
		$this->assertSame('meta', (new MagicMetadataColumn())->field(column: '_meta', columnToPropertyMap: []));
	}//end testAPrefixedColumnWithoutAPropertyIsMetadata()

	/**
	 * A property named like a metadata attribute does not take the metadata column.
	 *
	 * @return void
	 */
	public function testAMetadataAttributeWinsOverAPropertyOfTheSameName(): void {
		$this->assertSame('uuid', (new MagicMetadataColumn())->field(column: '_uuid', columnToPropertyMap: ['_uuid' => '_uuid']));
	}//end testAMetadataAttributeWinsOverAPropertyOfTheSameName()

	/**
	 * A declared `_` property is written; undeclared `_` keys and `@self` are not.
	 *
	 * @return void
	 */
	public function testOnlyADeclaredUnderscorePropertyIsWritten(): void {
		$roles = new MagicMetadataColumn();

		$this->assertFalse($roles->skippedOnWrite(key: '_meta', schemaProperties: ['_meta' => ['type' => 'object']]));
		$this->assertTrue($roles->skippedOnWrite(key: '_meta', schemaProperties: []));
		$this->assertTrue($roles->skippedOnWrite(key: '@self', schemaProperties: []));
		$this->assertFalse($roles->skippedOnWrite(key: 'code', schemaProperties: []));
	}//end testOnlyADeclaredUnderscorePropertyIsWritten()

	/**
	 * @return void
	 */
	public function testAttributeNamesFollowObjectEntity(): void {
		$this->assertSame('schemaVersion', (new MagicMetadataColumn())->attribute(field: 'schema_version'));
		$this->assertSame('id', (new MagicMetadataColumn())->attribute(field: 'id'));
		$this->assertNull((new MagicMetadataColumn())->attribute(field: 'meta'));
	}//end testAttributeNamesFollowObjectEntity()
}//end class
