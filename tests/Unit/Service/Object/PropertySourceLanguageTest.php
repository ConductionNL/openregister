<?php

/**
 * PropertySourceLanguage: the source language of a translatable property.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\PropertySourceLanguage;
use PHPUnit\Framework\TestCase;

/**
 * The fallback order: object meta, schema property, register default, then nl.
 *
 * @covers \OCA\OpenRegister\Service\Object\PropertySourceLanguage
 * @uses \OCA\OpenRegister\Db\Schema
 */
final class PropertySourceLanguageTest extends TestCase {

	/**
	 * A schema whose title property declares German as its source language.
	 *
	 * @return Schema
	 */
	private static function schema(): Schema {
		$schema = new Schema();
		$schema->setProperties(['title' => ['type' => 'string', 'sourceLanguage' => 'de'], 'body' => ['type' => 'string']]);

		return $schema;
	}//end schema()

	/**
	 * The object's own translation meta wins over the schema and the register.
	 *
	 * @return void
	 */
	public function testTheObjectMetaWins(): void {
		$language = new PropertySourceLanguage(schema: self::schema(), registerDefault: 'fr');

		$this->assertSame(
			expected: 'en',
			actual: $language->forProperty(body: ['_translationMeta' => ['title' => ['sourceLanguage' => 'en']]], property: 'title')
		);
	}//end testTheObjectMetaWins()

	/**
	 * Without usable meta the schema property's language applies.
	 *
	 * @return void
	 */
	public function testTheSchemaPropertyAppliesWithoutMeta(): void {
		$language = new PropertySourceLanguage(schema: self::schema(), registerDefault: 'fr');

		$this->assertSame(expected: 'de', actual: $language->forProperty(body: [], property: 'title'));
		$this->assertSame(expected: 'de', actual: $language->forProperty(body: ['_translationMeta' => 'broken'], property: 'title'));
		$this->assertSame(
			expected: 'de',
			actual: $language->forProperty(body: ['_translationMeta' => ['title' => ['sourceLanguage' => '']]], property: 'title')
		);
		$this->assertSame(
			expected: 'de',
			actual: $language->forProperty(body: ['_translationMeta' => ['title' => ['sourceLanguage' => 3]]], property: 'title')
		);
	}//end testTheSchemaPropertyAppliesWithoutMeta()

	/**
	 * A property without a declared language takes the register default.
	 *
	 * @return void
	 */
	public function testTheRegisterDefaultIsNext(): void {
		$language = new PropertySourceLanguage(schema: self::schema(), registerDefault: 'fr');

		$this->assertSame(expected: 'fr', actual: $language->forProperty(body: [], property: 'body'));
		$this->assertSame(expected: 'fr', actual: $language->forProperty(body: [], property: 'unknown'));
	}//end testTheRegisterDefaultIsNext()

	/**
	 * Without any declaration and no register default the language is nl.
	 *
	 * @return void
	 */
	public function testDutchIsTheLastResort(): void {
		$this->assertSame(
			expected: 'nl',
			actual: (new PropertySourceLanguage(schema: self::schema(), registerDefault: ''))->forProperty(body: [], property: 'body')
		);
		$this->assertSame(
			expected: 'nl',
			actual: (new PropertySourceLanguage(schema: new Schema(), registerDefault: ''))->forProperty(body: [], property: 'title')
		);
	}//end testDutchIsTheLastResort()
}//end class
