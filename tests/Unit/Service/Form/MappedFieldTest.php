<?php

/**
 * Unit tests for a form field mapped onto a schema property.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Service\Form\MappedField;
use PHPUnit\Framework\TestCase;

/**
 * A union property type reads as its members joined; a finding names field and property.
 *
 * @covers \OCA\OpenRegister\Service\Form\MappedField
 */
class MappedFieldTest extends TestCase {

	/**
	 * A union type is joined with a slash, a single type is read as is, a missing one is empty.
	 *
	 * @return void
	 */
	public function testThePropertyTypeOfAUnionIsJoined(): void {
		$this->assertSame('string/null', (new MappedField(['field' => 'naam'], ['type' => ['string', 'null']]))->propertyType());
		$this->assertSame('integer', (new MappedField(['field' => 'leeftijd'], ['type' => 'integer']))->propertyType());
		$this->assertSame('', (new MappedField(['field' => 'x'], []))->propertyType());

	}//end testThePropertyTypeOfAUnionIsJoined()

	/**
	 * A finding carries the field, the property, the code and the message.
	 *
	 * @return void
	 */
	public function testAFindingNamesFieldAndProperty(): void {
		$mapped = new MappedField(['field' => 'naam', 'property' => 'title', 'type' => 'text'], ['type' => 'string', 'format' => 'email']);

		$this->assertSame('text', $mapped->fieldType());
		$this->assertSame('email', $mapped->format());
		$this->assertSame(
			['field' => 'naam', 'property' => 'title', 'code' => 'type_mismatch', 'message' => 'm'],
			$mapped->finding('type_mismatch', 'm')
		);

	}//end testAFindingNamesFieldAndProperty()
}//end class
