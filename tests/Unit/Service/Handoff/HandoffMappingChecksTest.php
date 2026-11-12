<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Handoff\HandoffMappingChecks}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Handoff
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Handoff;

use OCA\OpenRegister\Service\Handoff\HandoffAnnotationEntry;
use OCA\OpenRegister\Service\Handoff\HandoffKindContract;
use OCA\OpenRegister\Service\Handoff\HandoffMappingChecks;
use PHPUnit\Framework\TestCase;

/**
 * A mapping block is checked against the target kind's contract and the five expression kinds.
 *
 * @covers \OCA\OpenRegister\Service\Handoff\HandoffMappingChecks
 * @uses   \OCA\OpenRegister\Service\Handoff\HandoffAnnotationEntry
 * @uses   \OCA\OpenRegister\Service\Handoff\HandoffKindContract
 */
class HandoffMappingChecksTest extends TestCase {

	/**
	 * A contract kind with two fields, one of them mandatory.
	 *
	 * @return HandoffKindContract
	 */
	private function contract(): HandoffKindContract {
		return new HandoffKindContract('https://example.org/Case', true, ['title', 'owner'], ['title']);

	}//end contract()

	/**
	 * Build an entry with the given mapping.
	 *
	 * @param mixed $mapping The mapping block.
	 *
	 * @return HandoffAnnotationEntry
	 */
	private function entry(mixed $mapping): HandoffAnnotationEntry {
		return new HandoffAnnotationEntry(['id' => 'to-case', 'mapping' => $mapping], 0, null, []);

	}//end entry()

	/**
	 * An absent or empty mapping is one bad-annotation fault and nothing else.
	 *
	 * @return void
	 */
	public function testEmptyMappingIsABadAnnotation(): void {
		$checks = new HandoffMappingChecks($this->contract());

		$expected = [[
			'code'    => 'handoff-bad-annotation',
			'message' => 'Handoff "to-case": a non-empty `mapping` object is required.',
		]];
		$this->assertSame($expected, $checks->errors($this->entry([])));
		$this->assertSame($expected, $checks->errors($this->entry('not a map')));

	}//end testEmptyMappingIsABadAnnotation()

	/**
	 * A complete, well-formed mapping yields no errors; `default` may sit beside `from`.
	 *
	 * @return void
	 */
	public function testValidMappingHasNoErrors(): void {
		$checks = new HandoffMappingChecks($this->contract());

		$errors = $checks->errors(
			$this->entry(
				[
					'title' => ['from' => 'name', 'default' => 'untitled'],
					'owner' => ['const' => 'admin'],
				]
			)
		);

		$this->assertSame([], $errors);

	}//end testValidMappingHasNoErrors()

	/**
	 * A field outside the contract is reported with the contract's field list.
	 *
	 * @return void
	 */
	public function testForeignFieldIsReported(): void {
		$checks = new HandoffMappingChecks($this->contract());

		$errors = $checks->errors(
			$this->entry(['title' => ['from' => 'name'], 'colour' => ['const' => 'red']])
		);

		$this->assertSame(
			[[
				'code'    => 'handoff-unknown-mapping-field',
				'message' => 'Handoff "to-case": mapping field "colour" is not part of the "https://example.org/Case" contract (fields: title, owner).',
			]],
			$errors
		);

	}//end testForeignFieldIsReported()

	/**
	 * A mandatory field left unmapped is one error listing every missing field.
	 *
	 * @return void
	 */
	public function testMissingMandatoryFieldIsReported(): void {
		$contract = new HandoffKindContract('urn:kind', true, ['a', 'b', 'c'], ['a', 'b']);
		$checks   = new HandoffMappingChecks($contract);

		$errors = $checks->errors($this->entry(['c' => ['const' => 1]]));

		$this->assertSame(
			[[
				'code'    => 'handoff-missing-mandatory-field',
				'message' => 'Handoff "to-case": mandatory "urn:kind" contract field(s) not mapped: a, b.',
			]],
			$errors
		);

	}//end testMissingMandatoryFieldIsReported()

	/**
	 * A non-contract kind accepts any field and has no mandatory fields.
	 *
	 * @return void
	 */
	public function testNonContractKindSkipsFieldChecks(): void {
		$contract = new HandoffKindContract('urn:free', false, ['x'], ['x']);
		$checks   = new HandoffMappingChecks($contract);

		$this->assertSame([], $checks->errors($this->entry(['anything' => ['template' => '{{a}}']])));

	}//end testNonContractKindSkipsFieldChecks()

	/**
	 * A scalar expression is rejected as malformed.
	 *
	 * @return void
	 */
	public function testScalarExpressionIsRejected(): void {
		$checks = new HandoffMappingChecks($this->contract());

		$errors = $checks->errors($this->entry(['title' => 'name']));

		$this->assertSame(
			[[
				'code'    => 'handoff-bad-mapping-expression',
				'message' => 'Handoff "to-case": mapping for "title" must be an object using one of: from, const, template, semanticRef, provenance.',
			]],
			$errors
		);

	}//end testScalarExpressionIsRejected()

	/**
	 * Unknown keys are named; a lone `default` or two kinds report `none`.
	 *
	 * @return void
	 */
	public function testOffendingExpressionKeys(): void {
		$checks = new HandoffMappingChecks($this->contract());
		$prefix = 'Handoff "to-case": mapping for "title" must use exactly one expression kind of [from, const, template, semanticRef, provenance]; offending key(s): ';

		$unknown = $checks->errors($this->entry(['title' => ['from' => 'a', 'xpath' => '/a', 'jq' => '.a']]));
		$this->assertSame([['code' => 'handoff-bad-mapping-expression', 'message' => $prefix.'xpath, jq.']], $unknown);

		$onlyDefault = $checks->errors($this->entry(['title' => ['default' => 'x']]));
		$this->assertSame([['code' => 'handoff-bad-mapping-expression', 'message' => $prefix.'none.']], $onlyDefault);

		$twoKinds = $checks->errors($this->entry(['title' => ['from' => 'a', 'const' => 'b']]));
		$this->assertSame([['code' => 'handoff-bad-mapping-expression', 'message' => $prefix.'none.']], $twoKinds);

	}//end testOffendingExpressionKeys()

	/**
	 * Field errors come before the mandatory-field error, and a foreign field can also be malformed.
	 *
	 * @return void
	 */
	public function testErrorsAreCollectedInOrder(): void {
		$checks = new HandoffMappingChecks($this->contract());

		$errors = $checks->errors($this->entry(['owner' => 'x', 'extra' => 'y']));

		$this->assertSame(
			[
				'handoff-bad-mapping-expression',
				'handoff-unknown-mapping-field',
				'handoff-bad-mapping-expression',
				'handoff-missing-mandatory-field',
			],
			array_column($errors, 'code')
		);

	}//end testErrorsAreCollectedInOrder()
}//end class
