<?php

/**
 * What a form field can produce, and whether that fits a destination property.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Service\Form\FormFieldRules;
use PHPUnit\Framework\TestCase;

/**
 * The compatibility table, the format derivation, the constraint comparison and fixed values.
 *
 * @covers \OCA\OpenRegister\Service\Form\FormFieldRules
 */
class FormFieldRulesTest extends TestCase {

	private FormFieldRules $rules;

	protected function setUp(): void {
		$this->rules = new FormFieldRules();
	}//end setUp()

	/**
	 * A field type is judged by what it produces against what the property accepts.
	 */
	public function testTypeFit(): void {
		$this->assertTrue($this->rules->typeFits(field: ['type' => 'text'], property: ['type' => 'string']));
		$this->assertTrue($this->rules->typeFits(field: ['type' => 'integer'], property: ['type' => 'number']));
		$this->assertFalse($this->rules->typeFits(field: ['type' => 'number'], property: ['type' => 'integer']));
		$this->assertTrue($this->rules->typeFits(field: ['type' => 'multichoice'], property: ['type' => 'array']));
		$this->assertTrue($this->rules->typeFits(field: ['type' => 'checkbox'], property: ['type' => ['boolean', 'null']]));
		$this->assertFalse($this->rules->typeFits(field: ['type' => 'text'], property: ['type' => 'boolean']));
		$this->assertTrue($this->rules->typeFits(field: ['type' => 'file'], property: ['type' => 'file']));
		$this->assertTrue($this->rules->typeFits(field: ['type' => 'files'], property: ['type' => 'array', 'items' => ['type' => 'file']]));
	}//end testTypeFit()

	/**
	 * An unknown or absent field type, or an untyped property, is not judged.
	 */
	public function testUnknownTypesAreNotJudged(): void {
		$this->assertTrue($this->rules->typeFits(field: [], property: ['type' => 'integer']));
		$this->assertTrue($this->rules->typeFits(field: ['type' => 'signature-pad'], property: ['type' => 'integer']));
		$this->assertTrue($this->rules->typeFits(field: ['type' => 'text'], property: []));
	}//end testUnknownTypesAreNotJudged()

	/**
	 * The format a field produces: explicit first, else derived from its type.
	 */
	public function testEffectiveFormat(): void {
		$this->assertSame('email', $this->rules->effectiveFormat(field: ['type' => 'email']));
		$this->assertSame('date', $this->rules->effectiveFormat(field: ['type' => 'date']));
		$this->assertSame('date-time', $this->rules->effectiveFormat(field: ['type' => 'datetime']));
		$this->assertSame('uuid', $this->rules->effectiveFormat(field: ['type' => 'reference']));
		$this->assertSame('bsn', $this->rules->effectiveFormat(field: ['type' => 'text', 'format' => 'bsn']));
		$this->assertNull($this->rules->effectiveFormat(field: ['type' => 'text']));
	}//end testEffectiveFormat()

	/**
	 * A property format is met only by the same format; a property without one accepts any.
	 */
	public function testFormatFit(): void {
		$this->assertTrue($this->rules->formatFits(field: ['type' => 'email'], property: ['type' => 'string', 'format' => 'email']));
		$this->assertFalse($this->rules->formatFits(field: ['type' => 'text'], property: ['type' => 'string', 'format' => 'email']));
		$this->assertTrue($this->rules->formatFits(field: ['type' => 'email'], property: ['type' => 'string']));
		$this->assertTrue($this->rules->formatFits(field: ['type' => 'datetime'], property: ['type' => 'string', 'format' => 'datetime']));
	}//end testFormatFit()

	/**
	 * Enum options as plain values or `{value, label}` objects; an array property's items enum counts.
	 */
	public function testEnumAndOptions(): void {
		$this->assertSame(['a', 'b'], $this->rules->enumOf(property: ['enum' => ['a', 'b']]));
		$this->assertSame(['x'], $this->rules->enumOf(property: ['type' => 'array', 'items' => ['enum' => ['x']]]));
		$this->assertNull($this->rules->enumOf(property: ['type' => 'string']));
		$this->assertSame(['a', 'b'], $this->rules->optionsOf(field: ['options' => ['a', ['value' => 'b', 'label' => 'B']]]));
		$this->assertNull($this->rules->optionsOf(field: ['type' => 'text']));
	}//end testEnumAndOptions()

	/**
	 * A missing or wider field bound is looser; an equal or tighter one is not.
	 */
	public function testLooserConstraints(): void {
		$this->assertSame(
			['maxLength', 'minLength'],
			$this->rules->looserConstraints(field: ['maxLength' => 300, 'minLength' => 1], property: ['maxLength' => 200, 'minLength' => 2])
		);
		$this->assertSame(['maximum'], $this->rules->looserConstraints(field: ['minimum' => 5], property: ['minimum' => 0, 'maximum' => 10]));
		$this->assertSame(['pattern'], $this->rules->looserConstraints(field: [], property: ['pattern' => '^[0-9]{4}$']));
		$this->assertSame([], $this->rules->looserConstraints(field: ['maxLength' => 100, 'pattern' => '^x$'], property: ['maxLength' => 200, 'pattern' => '^[a-z]$']));
		$this->assertSame([], $this->rules->looserConstraints(field: [], property: ['type' => 'string']));
	}//end testLooserConstraints()

	/**
	 * A fixed value fits by JSON type and enum; null fits only a nullable property.
	 */
	public function testFixedValueFit(): void {
		$this->assertTrue($this->rules->fixedValueFits(value: 'web', property: ['type' => 'string']));
		$this->assertFalse($this->rules->fixedValueFits(value: 'veel', property: ['type' => 'integer']));
		$this->assertTrue($this->rules->fixedValueFits(value: 3, property: ['type' => 'number']));
		$this->assertFalse($this->rules->fixedValueFits(value: 3.5, property: ['type' => 'integer']));
		$this->assertFalse($this->rules->fixedValueFits(value: 'fax', property: ['type' => 'string', 'enum' => ['web']]));
		$this->assertTrue($this->rules->fixedValueFits(value: null, property: ['type' => ['string', 'null']]));
		$this->assertFalse($this->rules->fixedValueFits(value: null, property: ['type' => 'string']));
		$this->assertTrue($this->rules->fixedValueFits(value: ['a'], property: ['type' => 'array']));
		$this->assertTrue($this->rules->fixedValueFits(value: ['k' => 'v'], property: ['type' => 'object']));
		$this->assertTrue($this->rules->fixedValueFits(value: 'anything', property: []));
	}//end testFixedValueFit()
}//end class
