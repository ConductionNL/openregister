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
		$this->assertNull(MagicMetadataColumn::field(column: 'code', columnToPropertyMap: []));
	}//end testAColumnWithoutThePrefixIsAProperty()

	/**
	 * @return void
	 */
	public function testAPrefixedColumnTheSchemaMapsToAPropertyIsThatProperty(): void {
		$this->assertNull(MagicMetadataColumn::field(column: '_meta', columnToPropertyMap: ['_meta' => '_meta']));
	}//end testAPrefixedColumnTheSchemaMapsToAPropertyIsThatProperty()

	/**
	 * Control: the same column without a property in the schema is metadata.
	 *
	 * @return void
	 */
	public function testAPrefixedColumnWithoutAPropertyIsMetadata(): void {
		$this->assertSame('meta', MagicMetadataColumn::field(column: '_meta', columnToPropertyMap: []));
	}//end testAPrefixedColumnWithoutAPropertyIsMetadata()

	/**
	 * A property named like a metadata attribute does not take the metadata column.
	 *
	 * @return void
	 */
	public function testAMetadataAttributeWinsOverAPropertyOfTheSameName(): void {
		$this->assertSame('uuid', MagicMetadataColumn::field(column: '_uuid', columnToPropertyMap: ['_uuid' => '_uuid']));
	}//end testAMetadataAttributeWinsOverAPropertyOfTheSameName()

	/**
	 * @return void
	 */
	public function testAttributeNamesFollowObjectEntity(): void {
		$this->assertSame('schemaVersion', MagicMetadataColumn::attribute(field: 'schema_version'));
		$this->assertSame('id', MagicMetadataColumn::attribute(field: 'id'));
		$this->assertNull(MagicMetadataColumn::attribute(field: 'meta'));
	}//end testAttributeNamesFollowObjectEntity()
}//end class
