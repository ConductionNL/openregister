<?php

/**
 * The source of an inherited property, with subschema entries in allOf.
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
use OCA\OpenRegister\Db\SchemaMapper\SchemaComposition;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\SchemaMapper\SchemaComposition
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\ParentReference
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class SchemaCompositionPropertySourceTest extends TestCase {
	private function composition(): SchemaComposition {
		return new SchemaComposition(
			loadSchema: static function (string|int $identifier): Schema {
				$parent = new Schema();
				$parent->setId(2);
				$parent->setSlug((string)$identifier);
				$parent->setProperties(['name' => ['type' => 'string']]);
				return $parent;
			}
		);
	}//end composition()

	/**
	 * An inline subschema names no parent; it raised a TypeError past the catch.
	 *
	 * @return void
	 */
	public function testAnInlineEntryIsSkipped(): void {
		$source = $this->composition()->propertySource(propertyName: 'name', parentRefs: [['required' => ['name']], 'person']);

		$this->assertSame('person', $source);
	}//end testAnInlineEntryIsSkipped()

	/**
	 * A `$ref` entry is reported by the parent it names.
	 *
	 * @return void
	 */
	public function testARefEntryNamesItsParent(): void {
		$source = $this->composition()->propertySource(propertyName: 'name', parentRefs: [['$ref' => '#/components/schemas/person']]);

		$this->assertSame('person', $source);
	}//end testARefEntryNamesItsParent()
}//end class
