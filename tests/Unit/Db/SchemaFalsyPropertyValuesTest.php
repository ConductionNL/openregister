<?php

declare(strict_types=1);

/**
 * Schema Falsy Property Value Unit Tests
 *
 * Locks the property filter in Schema::getSchemaObject(). The filter drops an
 * empty value, so a property declaring `default: false` came back carrying no
 * default at all, SaveObject::setDefaultValues() found nothing to apply, and a
 * created object stored NULL where `false` belonged -- which made it invisible
 * to a list filtering on `false`. The value-carrying keys are exempt from the
 * filter; `''` is not, being what the property form ships for a default the
 * author never filled in.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Db;

use OCA\OpenRegister\Db\Schema;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the falsy-value exemption in getSchemaObject().
 */
class SchemaFalsyPropertyValuesTest extends TestCase {

	/**
	 * A URL generator that answers the one call getSchemaObject() makes.
	 *
	 * @return IURLGenerator The stub.
	 */
	private function urlGenerator(): IURLGenerator {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('https://example.test');

		return $urlGenerator;
	}//end urlGenerator()

	/**
	 * Build a schema carrying one property with the given declaration.
	 *
	 * @param array<string, mixed> $property The property declaration.
	 *
	 * @return \stdClass The emitted schema object.
	 */
	private function emit(array $property): \stdClass {
		$schema = new Schema();
		$schema->hydrate(object: ['title' => 'Case', 'properties' => ['flag' => $property]]);

		return $schema->getSchemaObject(urlGenerator: $this->urlGenerator());
	}//end emit()

	/**
	 * The defect this guards: `default: false` reached the emitted schema as no
	 * default at all, so nothing was left for setDefaultValues() to apply.
	 *
	 * @return void
	 */
	public function testFalseDefaultSurvives(): void {
		$prop = $this->emit(['title' => 'flag', 'type' => 'boolean', 'default' => false]);

		$this->assertObjectHasProperty('default', $prop->properties->flag);
		$this->assertFalse($prop->properties->flag->default);
	}//end testFalseDefaultSurvives()

	/**
	 * A numeric zero default is the same defect wearing another type.
	 *
	 * @return void
	 */
	public function testZeroDefaultSurvives(): void {
		$prop = $this->emit(['title' => 'flag', 'type' => 'integer', 'default' => 0]);

		$this->assertObjectHasProperty('default', $prop->properties->flag);
		$this->assertSame(0, $prop->properties->flag->default);
	}//end testZeroDefaultSurvives()

	/**
	 * `const` is read unconditionally by setDefaultValues(), so a falsy one was
	 * equally unreachable.
	 *
	 * @return void
	 */
	public function testFalseConstSurvives(): void {
		$prop = $this->emit(['title' => 'flag', 'type' => 'boolean', 'const' => false]);

		$this->assertObjectHasProperty('const', $prop->properties->flag);
		$this->assertFalse($prop->properties->flag->const);
	}//end testFalseConstSurvives()

	/**
	 * A zero floor is a real constraint, and the only falsy value a generated
	 * schema produces today (TablesColumnMapper::numberProperty()).
	 *
	 * @return void
	 */
	public function testZeroMinimumSurvives(): void {
		$prop = $this->emit(['title' => 'flag', 'type' => 'integer', 'minimum' => 0, 'maximum' => 100]);

		$this->assertSame(0, $prop->properties->flag->minimum);
		$this->assertSame(100, $prop->properties->flag->maximum);
	}//end testZeroMinimumSurvives()

	/**
	 * An empty-string default stays stripped: the property form ships
	 * `default: ''` for a field nobody filled in, so emitting it would write
	 * `''` in place of NULL for nearly every property on the instance.
	 *
	 * @return void
	 */
	public function testEmptyStringDefaultIsStillStripped(): void {
		$prop = $this->emit(['title' => 'flag', 'type' => 'string', 'default' => '']);

		$this->assertObjectNotHasProperty('default', $prop->properties->flag);
	}//end testEmptyStringDefaultIsStillStripped()

	/**
	 * Every other key keeps today's behaviour: an empty one says nothing, and
	 * is dropped as before.
	 *
	 * @return void
	 */
	public function testEmptyMetadataKeysAreStillStripped(): void {
		$prop = $this->emit(
			[
				'title' => 'flag',
				'type' => 'boolean',
				'description' => '',
				'pattern' => '',
				'default' => false,
			]
		);

		$this->assertObjectNotHasProperty('description', $prop->properties->flag);
		$this->assertObjectNotHasProperty('pattern', $prop->properties->flag);
		$this->assertFalse($prop->properties->flag->default);
	}//end testEmptyMetadataKeysAreStillStripped()
}//end class
