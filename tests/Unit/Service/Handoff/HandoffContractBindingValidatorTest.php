<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Handoff\HandoffContractBindingValidator}.
 *
 * Accept/reject matrix for the provider-side `handoffContract` binding
 * block: complete bindings accepted, incomplete mandatory coverage rejected
 * with `handoff-contract-incomplete` (listing the missing fields), bindings
 * to non-existent own properties rejected, no-binding-block schemas pass
 * (they are simply not providers), and the engine's `isCompleteBinding`
 * filter helper.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Handoff
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Handoff;

use OCA\OpenRegister\Service\Handoff\HandoffContractBindingValidator;
use PHPUnit\Framework\TestCase;

/**
 * HandoffContractBindingValidatorTest.
 */
class HandoffContractBindingValidatorTest extends TestCase {

	private const CASE_URI = 'https://openregister.app/ns#Case';

	private HandoffContractBindingValidator $validator;

	protected function setUp(): void {
		$this->validator = new HandoffContractBindingValidator();

	}//end setUp()

	/**
	 * A binding covering every mandatory ns#Case field is accepted.
	 *
	 * @return void
	 */
	public function testCompleteBindingIsAccepted(): void {
		$this->assertSame([], $this->validator->validate($this->shape()));

	}//end testCompleteBindingIsAccepted()

	/**
	 * No binding block ⇒ not a handoff provider — never an error.
	 *
	 * @return void
	 */
	public function testNoBindingBlockPasses(): void {
		$shape = $this->shape();
		unset($shape['handoffContract']);
		$this->assertSame([], $this->validator->validate($shape));

	}//end testNoBindingBlockPasses()

	/**
	 * Omitting a mandatory contract field → handoff-contract-incomplete,
	 * message listing the missing field.
	 *
	 * @return void
	 */
	public function testMissingMandatoryFieldIsRejected(): void {
		$shape = $this->shape();
		unset($shape['handoffContract'][self::CASE_URI]['channel']);

		$errors = $this->validator->validate($shape);
		$this->assertNotSame([], $errors);
		$this->assertSame('handoff-contract-incomplete', $errors[0]['code']);
		$this->assertStringContainsString('channel', $errors[0]['message']);

	}//end testMissingMandatoryFieldIsRejected()

	/**
	 * Binding a contract field to a property the schema lacks is rejected.
	 *
	 * @return void
	 */
	public function testBindingToMissingPropertyIsRejected(): void {
		$shape = $this->shape();
		$shape['handoffContract'][self::CASE_URI]['title'] = 'ghost';

		$codes = array_map(static fn (array $e) => $e['code'], $this->validator->validate($shape));
		$this->assertContains('handoff-contract-incomplete', $codes);

	}//end testBindingToMissingPropertyIsRejected()

	/**
	 * Binding a kind the schema does not implement is rejected.
	 *
	 * @return void
	 */
	public function testBindingUnimplementedKindIsRejected(): void {
		$shape = $this->shape();
		$shape['implements'] = ['https://openregister.app/ns#Quote'];

		$codes = array_map(static fn (array $e) => $e['code'], $this->validator->validate($shape));
		$this->assertContains('handoff-contract-incomplete', $codes);

	}//end testBindingUnimplementedKindIsRejected()

	/**
	 * `isCompleteBinding` — the engine's provider filter.
	 *
	 * @return void
	 */
	public function testIsCompleteBinding(): void {
		$shape = $this->shape();
		$this->assertTrue(
			HandoffContractBindingValidator::isCompleteBinding(
				kindUri: self::CASE_URI,
				binding: $shape['handoffContract'],
				properties: $shape['properties']
			)
		);

		// Missing mandatory field → incomplete.
		$incomplete = $shape['handoffContract'];
		unset($incomplete[self::CASE_URI]['source']);
		$this->assertFalse(
			HandoffContractBindingValidator::isCompleteBinding(
				kindUri: self::CASE_URI,
				binding: $incomplete,
				properties: $shape['properties']
			)
		);

		// Kind not bound at all → incomplete.
		$this->assertFalse(
			HandoffContractBindingValidator::isCompleteBinding(
				kindUri: 'https://openregister.app/ns#Quote',
				binding: $shape['handoffContract'],
				properties: $shape['properties']
			)
		);

	}//end testIsCompleteBinding()

	/**
	 * Build the canonical provider shape (procest-like `case` schema).
	 *
	 * @return array<string, mixed>
	 */
	private function shape(): array {
		return [
			'properties' => [
				'onderwerp' => ['type' => 'string'],
				'omschrijving' => ['type' => 'string'],
				'aanvrager' => ['type' => 'string'],
				'kanaal' => ['type' => 'string'],
				'prioriteit' => ['type' => 'string'],
				'herkomst' => ['type' => 'object'],
			],
			'implements' => [self::CASE_URI],
			'handoffContract' => [
				self::CASE_URI => [
					'title' => 'onderwerp',
					'summary' => 'omschrijving',
					'requester' => 'aanvrager',
					'channel' => 'kanaal',
					'priority' => 'prioriteit',
					'source' => 'herkomst',
				],
			],
		];

	}//end shape()
}//end class
