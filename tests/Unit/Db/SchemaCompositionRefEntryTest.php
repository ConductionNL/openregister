<?php

/**
 * An allOf entry written as a $ref is a parent on read as it is on save.
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

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\SchemaComposition;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\SchemaMapper\SchemaComposition
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\ParentReference
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\PropertyDelta
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\PropertyMerge
 * @uses   \OCA\OpenRegister\Db\SchemaPropertyInheritance
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class SchemaCompositionRefEntryTest extends TestCase {
	private const PARENT_PROPERTIES = ['name' => ['type' => 'string', 'title' => 'Name']];

	private static function schema(int $id, string $slug): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setUuid('uuid-' . $id);
		$schema->setSlug($slug);
		return $schema;
	}//end schema()

	private function compositionWith(?Schema $parent): SchemaComposition {
		return new SchemaComposition(
			loadSchema: static function (string|int $identifier) use ($parent): Schema {
				if ($parent === null || $identifier !== $parent->getSlug()) {
					throw new Exception("Schema '{$identifier}' not found");
				}

				return $parent;
			}
		);
	}//end compositionWith()

	private function parent(): Schema {
		$parent = self::schema(2, 'person');
		$parent->setProperties(self::PARENT_PROPERTIES);
		$parent->setRequired(['name']);
		return $parent;
	}//end parent()

	private function child(string $ref): Schema {
		$child = self::schema(1, 'employee');
		$child->setAllOf([['$ref' => $ref]]);
		$child->setProperties(self::PARENT_PROPERTIES + ['own' => ['type' => 'string']]);
		$child->setRequired(['name', 'own']);
		return $child;
	}//end child()

	/**
	 * The save strips what the parent holds; the read has to bring it back.
	 *
	 * @return void
	 */
	public function testWhatTheSaveStripsTheReadRestores(): void {
		$composition = $this->compositionWith($this->parent());
		$stored = $composition->extractDelta(schema: $this->child('#/components/schemas/person'));
		$this->assertSame(['own'], array_keys($stored->getProperties()));

		$read = $composition->resolve(schema: $stored);

		$this->assertSame(['name', 'own'], array_keys($read->getProperties()));
		$this->assertSame(['name', 'own'], array_values($read->getRequired()));
	}//end testWhatTheSaveStripsTheReadRestores()

	/**
	 * A parent that is not there yet leaves the schema readable, as it always was.
	 *
	 * @return void
	 */
	public function testAMissingRefParentIsSkipped(): void {
		$read = $this->compositionWith(null)->resolve(schema: $this->child('person'));

		$this->assertSame(['name', 'own'], array_keys($read->getProperties()));
	}//end testAMissingRefParentIsSkipped()

	/**
	 * A `$ref` to the schema itself names no parent to merge, and must not break the read.
	 *
	 * @return void
	 */
	public function testARefToItselfIsSkipped(): void {
		$child = $this->child('employee');

		$read = $this->compositionWith(self::schema(1, 'employee'))->resolve(schema: $child);

		$this->assertSame(['name', 'own'], array_keys($read->getProperties()));
	}//end testARefToItselfIsSkipped()

	/**
	 * A child that relaxes a `$ref` parent stays readable; a scalar parent still refuses.
	 *
	 * @return void
	 */
	public function testARefParentTheChildRelaxesIsSkipped(): void {
		$child = self::schema(1, 'employee');
		$child->setAllOf([['$ref' => 'person']]);
		$child->setProperties(['name' => ['type' => 'integer']]);

		$read = $this->compositionWith($this->parent())->resolve(schema: $child);

		$this->assertSame(['name' => ['type' => 'integer']], $read->getProperties());
	}//end testARefParentTheChildRelaxesIsSkipped()

	/**
	 * A scalar parent keeps its stricter rule: missing refuses.
	 *
	 * @return void
	 */
	public function testAMissingScalarParentStillRefuses(): void {
		$child = self::schema(1, 'employee');
		$child->setAllOf(['person']);

		$this->expectExceptionMessage("Schema 'person' not found");

		$this->compositionWith(null)->resolve(schema: $child);
	}//end testAMissingScalarParentStillRefuses()
}//end class
