<?php

/**
 * The dependent value table: refused at schema save when it names nothing,
 * refused at object save when a value falls outside the pairs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use OCA\OpenRegister\Service\Rules\DependentValueTable;
use OCA\OpenRegister\Service\Rules\DependentValueValidator;
use PHPUnit\Framework\TestCase;

/**
 * Task 4.3 and REQ-REO-005.
 *
 * The proving case is the Dutch one the candidate note names: zaaktype to
 * resultaattype, which is a table in the Selectielijst and not a rule per pair.
 *
 * @package OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
 */
class DependentValueTableTest extends TestCase {

	/**
	 * The properties of a schema whose `resultaat` follows its `zaaktype`.
	 *
	 * @param array<string, mixed> $table The table to declare, or an empty array for the sound one.
	 *
	 * @return array<string, mixed> The properties block.
	 */
	private function properties(array $table = []): array {
		if ($table === []) {
			$table = [
				'controlledBy' => 'zaaktype',
				'allowed' => [
					'bezwaar' => ['gegrond', 'ongegrond'],
					'klacht' => ['afgehandeld'],
				],
			];
		}

		return [
			'zaaktype' => ['type' => 'string', 'enum' => ['bezwaar', 'klacht', 'melding']],
			'resultaat' => [
				'type' => 'string',
				'enum' => ['gegrond', 'ongegrond', 'afgehandeld', 'verleend'],
				DependentValueTable::ANNOTATION => $table,
			],
		];
	}//end properties()

	/**
	 * The codes a schema save would produce for these properties.
	 *
	 * @param array<string, mixed> $properties The properties block.
	 *
	 * @return array<int, string> The codes.
	 */
	private function codesFor(array $properties): array {
		$errors = (new DependentValueValidator())->validate(['properties' => $properties]);

		return array_map(static fn (array $error): string => (string)$error['code'], $errors);
	}//end codesFor()

	/**
	 * 🔴 THE CONTROL. A sound table produces no error at all, which is what
	 * makes every "this code was raised" assertion below about the fault named
	 * rather than about the fixture.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testASoundTableIsAccepted(): void {
		$this->assertSame([], $this->codesFor($this->properties()));
	}//end testASoundTableIsAccepted()

	/**
	 * A value outside the pairs allowed by the controlling value is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testAValueOutsideTheTableIsRefusedNamingBothProperties(): void {
		$violations = (new DependentValueTable())->violations(
			properties: $this->properties(),
			data: ['zaaktype' => 'bezwaar', 'resultaat' => 'verleend']
		);

		$this->assertCount(1, $violations);
		$this->assertSame('resultaat', $violations[0]['property']);
		$this->assertSame('zaaktype', $violations[0]['controlledBy']);
		$this->assertStringContainsString('resultaat', $violations[0]['message']);
		$this->assertStringContainsString('zaaktype', $violations[0]['message']);
		$this->assertStringContainsString('gegrond, ongegrond', $violations[0]['message']);
	}//end testAValueOutsideTheTableIsRefusedNamingBothProperties()

	/**
	 * A value inside the pairs is allowed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testAValueInsideTheTableIsAllowed(): void {
		$this->assertSame(
			[],
			(new DependentValueTable())->violations(
				properties: $this->properties(),
				data: ['zaaktype' => 'bezwaar', 'resultaat' => 'ongegrond']
			)
		);
	}//end testAValueInsideTheTableIsAllowed()

	/**
	 * A patch that changes only the dependent property is judged against the
	 * controlling value the object actually has.
	 *
	 * 🔴 Without this the table is bypassed by sending one field: the payload
	 * has no zaaktype, so nothing constrains the resultaat.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testAPartialWriteIsJudgedAgainstTheStoredControllingValue(): void {
		$violations = (new DependentValueTable())->violations(
			properties: $this->properties(),
			data: ['resultaat' => 'verleend'],
			stored: ['zaaktype' => 'bezwaar', 'resultaat' => 'gegrond']
		);

		$this->assertCount(1, $violations);
		$this->assertSame('bezwaar', $violations[0]['controllingValue']);
	}//end testAPartialWriteIsJudgedAgainstTheStoredControllingValue()

	/**
	 * A controlling value the table does not list constrains nothing.
	 *
	 * Documented behaviour, not an oversight: it is what lets a table cover
	 * the three zaaktypen that need one without enumerating every other.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testAnUnlistedControllingValueConstrainsNothing(): void {
		$this->assertSame(
			[],
			(new DependentValueTable())->violations(
				properties: $this->properties(),
				data: ['zaaktype' => 'melding', 'resultaat' => 'verleend']
			)
		);
	}//end testAnUnlistedControllingValueConstrainsNothing()

	/**
	 * A table naming an undeclared property is refused at schema save.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testATableNamingAnUnknownPropertyIsRefusedAtSchemaSave(): void {
		$codes = $this->codesFor(
			$this->properties(['controlledBy' => 'zaakType', 'allowed' => ['bezwaar' => ['gegrond']]])
		);

		$this->assertContains(DependentValueValidator::CODE_UNKNOWN_PROPERTY, $codes);
	}//end testATableNamingAnUnknownPropertyIsRefusedAtSchemaSave()

	/**
	 * The refusal names the property that is missing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testTheUnknownPropertyRefusalNamesIt(): void {
		$errors = (new DependentValueValidator())->validate(
			['properties' => $this->properties(['controlledBy' => 'zaakType', 'allowed' => ['bezwaar' => ['gegrond']]])]
		);

		$this->assertStringContainsString('zaakType', $errors[0]['message']);
	}//end testTheUnknownPropertyRefusalNamesIt()

	/**
	 * A key of `allowed` the controlling property cannot take is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testAnUnknownControllingValueIsRefusedAtSchemaSave(): void {
		$codes = $this->codesFor(
			$this->properties(['controlledBy' => 'zaaktype', 'allowed' => ['aanvraag' => ['gegrond']]])
		);

		$this->assertContains(DependentValueValidator::CODE_UNKNOWN_CONTROLLING_VALUE, $codes);
	}//end testAnUnknownControllingValueIsRefusedAtSchemaSave()

	/**
	 * A listed value the dependent property itself cannot take is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testAListedValueThePropertyCannotTakeIsRefused(): void {
		$codes = $this->codesFor(
			$this->properties(['controlledBy' => 'zaaktype', 'allowed' => ['bezwaar' => ['toegewezen']]])
		);

		$this->assertContains(DependentValueValidator::CODE_UNKNOWN_VALUE, $codes);
	}//end testAListedValueThePropertyCannotTakeIsRefused()

	/**
	 * A property cannot control its own allowed values.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testAPropertyCannotControlItself(): void {
		$codes = $this->codesFor(
			$this->properties(['controlledBy' => 'resultaat', 'allowed' => ['gegrond' => ['gegrond']]])
		);

		$this->assertContains(DependentValueValidator::CODE_SELF_CONTROLLED, $codes);
	}//end testAPropertyCannotControlItself()

	/**
	 * A malformed table is refused rather than skipped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testAMalformedTableIsRefused(): void {
		$this->assertContains(DependentValueValidator::CODE_MALFORMED, $this->codesFor($this->properties(['controlledBy' => 'zaaktype'])));
		$this->assertContains(DependentValueValidator::CODE_MALFORMED, $this->codesFor($this->properties(['allowed' => ['bezwaar' => ['gegrond']]])));
	}//end testAMalformedTableIsRefused()

	/**
	 * A schema that declares no table is untouched, and says so cheaply.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testASchemaWithNoTableIsUntouched(): void {
		$properties = ['zaaktype' => ['type' => 'string'], 'resultaat' => ['type' => 'string']];
		$table = new DependentValueTable();

		$this->assertFalse($table->declaresAny(properties: $properties));
		$this->assertSame([], $table->tablesOf(properties: $properties));
		$this->assertSame([], $table->violations(properties: $properties, data: ['resultaat' => 'anything']));
		$this->assertSame([], $this->codesFor($properties));
	}//end testASchemaWithNoTableIsUntouched()

	/**
	 * An absent dependent value is not the table's question.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function testAnAbsentDependentValueIsNotRefused(): void {
		$this->assertSame(
			[],
			(new DependentValueTable())->violations(
				properties: $this->properties(),
				data: ['zaaktype' => 'bezwaar']
			)
		);
	}//end testAnAbsentDependentValueIsNotRefused()
}//end class
